<?php

namespace Mautic\LeadBundle\Controller;

use Mautic\CampaignBundle\Membership\MembershipManager;
use Mautic\CampaignBundle\Model\CampaignModel;
use Mautic\CoreBundle\Controller\AjaxController as CommonAjaxController;
use Mautic\CoreBundle\Controller\AjaxLookupControllerTrait;
use Mautic\CoreBundle\Helper\InputHelper;
use Mautic\LeadBundle\Entity\DoNotContact;
use Mautic\LeadBundle\Entity\UtmTag;
use Mautic\LeadBundle\Event\LeadTimelineEvent;
use Mautic\LeadBundle\Form\Type\FilterPropertiesType;
use Mautic\LeadBundle\Helper\FormFieldHelper;
use Mautic\LeadBundle\LeadEvents;
use Mautic\LeadBundle\Model\CompanyModel;
use Mautic\LeadBundle\Model\DoNotContact as DoNotContactModel;
use Mautic\LeadBundle\Model\FieldModel;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\LeadBundle\Model\ListModel;
use Mautic\LeadBundle\Provider\FormAdjustmentsProviderInterface;
use Mautic\LeadBundle\Segment\Stat\SegmentCampaignShare;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class AjaxController extends CommonAjaxController
{
    use AjaxLookupControllerTrait;

    /**
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    protected function userListAction(Request $request)
    {
        $filter    = InputHelper::clean($request->query->get('filter'));
        $results   = $this->getModel('lead.lead')->getLookupResults('user', $filter);
        $dataArray = [];
        foreach ($results as $r) {
            $name        = $r['firstName'].' '.$r['lastName'];
            $dataArray[] = [
                'label' => $name,
                'value' => $r['id'],
            ];
        }

        return $this->sendJsonResponse($dataArray);
    }

    /**
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    protected function getLeadIdsByFieldValueAction(Request $request)
    {
        $field     = InputHelper::clean($request->request->get('field'));
        $value     = InputHelper::clean($request->request->get('value'));
        $ignore    = (int) $request->request->get('ignore');
        $dataArray = ['items' => []];

        if ($field && $value) {
            $repo                       = $this->getModel('lead.lead')->getRepository();
            $leads                      = $repo->getLeadsByFieldValue($field, $value, $ignore);
            $dataArray['existsMessage'] = $this->translator->trans('mautic.lead.exists.by.field').': ';

            foreach ($leads as $lead) {
                $fields = $repo->getFieldValues($lead->getId());
                $lead->setFields($fields);
                $name = $lead->getName();

                if (!$name) {
                    $name = $lead->getEmail();
                }

                if (!$name) {
                    $name = $this->translator->trans('mautic.lead.lead.anonymous');
                }

                $leadLink = $this->generateUrl('mautic_contact_action', ['objectAction' => 'view', 'objectId' => $lead->getId()]);

                $dataArray['items'][] = [
                    'name' => $name,
                    'id'   => $lead->getId(),
                    'link' => $leadLink,
                ];
            }
        }

        return $this->sendJsonResponse($dataArray);
    }

    /**
     * @return JsonResponse
     */
    protected function fieldListAction(Request $request)
    {
        $dataArray  = ['success' => 1];
        $filter     = InputHelper::clean($request->query->get('filter'));
        $fieldAlias = InputHelper::alphanum($request->query->get('field'), false, false, ['_']);

        /** @var FieldModel $fieldModel */
        $fieldModel = $this->getModel('lead.field');

        /** @var LeadModel $contactModel */
        $contactModel = $this->getModel('lead.lead');

        /** @var CompanyModel $companyModel */
        $companyModel = $this->getModel('lead.company');

        if (empty($fieldAlias)) {
            $dataArray['error']   = 'Alias cannot be empty';
            $dataArray['success'] = 0;

            return $this->sendJsonResponse($dataArray);
        }

        if ('owner_id' === $fieldAlias) {
            $results = $contactModel->getLookupResults('user', $filter);
            foreach ($results as $r) {
                $name        = $r['firstName'].' '.$r['lastName'];
                $dataArray[] = [
                    'value' => $name,
                    'id'    => $r['id'],
                ];
            }

            return $this->sendJsonResponse($dataArray);
        }

        $field      = $fieldModel->getEntityByAlias($fieldAlias);
        $isBehavior = empty($field);

        if ($isBehavior) {
            return $this->sendJsonResponse($dataArray);
        }

        // Selet field types that make sense to provide typeahead for.
        $isLookup     = in_array($field->getType(), ['lookup']);
        $shouldLookup = in_array($field->getAlias(), ['city', 'company', 'title']);

        if (!$isLookup && !$shouldLookup) {
            return $this->sendJsonResponse($dataArray);
        }

        if ($isLookup && !empty($field->getProperties()['list'])) {
            foreach ($field->getProperties()['list'] as $predefinedValue) {
                $dataArray[] = ['value' => $predefinedValue];
            }
        }

        if ('company' === $field->getObject()) {
            $results = $companyModel->getLookupResults('companyfield', [$fieldAlias, $filter]);
            foreach ($results as $r) {
                $dataArray[] = ['value' => $r['label']];
            }
        } elseif ('lead' === $field->getObject()) {
            $results = $fieldModel->getLookupResults($fieldAlias, $filter);
            foreach ($results as $r) {
                $dataArray[] = ['value' => $r[$fieldAlias]];
            }
        }

        return $this->sendJsonResponse($dataArray);
    }

    protected function loadSegmentFilterFormAction(Request $request): JsonResponse
    {
        $fieldAlias  = InputHelper::clean($request->request->get('fieldAlias'));
        $fieldObject = InputHelper::clean($request->request->get('fieldObject'));
        $operator    = InputHelper::clean($request->request->get('operator'));
        $filterNum   = (int) $request->request->get('filterNum');

        /** @var FormFactoryInterface $formFactory */
        $formFactory = $this->get('form.factory');

        /** @var FormAdjustmentsProviderInterface $formAdjustmentsProvider */
        $formAdjustmentsProvider = $this->get('mautic.lead.provider.formAdjustments');

        /** @var ListModel $listModel */
        $listModel = $this->get('mautic.lead.model.list');

        $form = $formFactory->createNamed('RENAME', FilterPropertiesType::class);

        if ($fieldAlias && $operator) {
            $formAdjustmentsProvider->adjustForm(
                $form,
                $fieldAlias,
                $fieldObject,
                $operator,
                $listModel->getChoiceFields()[$fieldObject][$fieldAlias]
            );
        }

        $formHtml = $this->renderView(
            'MauticLeadBundle:List:filterpropform.html.php',
            [
                'form' => $this->setFormTheme($form, 'MauticLeadBundle:List:filterpropform.html.php', []),
            ]
        );

        $formHtml = str_replace('id="RENAME', "id=\"leadlist_filters_{$filterNum}_properties", $formHtml);
        $formHtml = str_replace('name="RENAME', "name=\"leadlist[filters][{$filterNum}][properties]", $formHtml);

        return $this->sendJsonResponse(
            [
                'viewParameters' => [
                    'form' => $formHtml,
                ],
            ]
        );
    }

    /**
     * Updates the cache and gets returns updated HTML.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    protected function updateSocialProfileAction(Request $request)
    {
        $dataArray = ['success' => 0];
        $network   = InputHelper::clean($request->request->get('network'));
        $leadId    = InputHelper::clean($request->request->get('lead'));

        if (!empty($leadId)) {
            //find the lead
            $model = $this->getModel('lead.lead');
            $lead  = $model->getEntity($leadId);

            if (null !== $lead && $this->get('mautic.security')->hasEntityAccess('lead:leads:editown', 'lead:leads:editown', $lead->getPermissionUser())) {
                $leadFields = $lead->getFields();
                /** @var IntegrationHelper $integrationHelper */
                $integrationHelper = $this->factory->getHelper('integration');
                $socialProfiles    = $integrationHelper->getUserProfiles($lead, $leadFields, true, $network);
                $socialProfileUrls = $integrationHelper->getSocialProfileUrlRegex(false);
                $integrations      = [];
                $socialCount       = count($socialProfiles);
                if (empty($network) || empty($socialCount)) {
                    $dataArray['completeProfile'] = $this->renderView(
                        'MauticLeadBundle:Social:index.html.php',
                        [
                            'socialProfiles'    => $socialProfiles,
                            'lead'              => $lead,
                            'socialProfileUrls' => $socialProfileUrls,
                        ]
                    );
                    $dataArray['socialCount'] = $socialCount;
                } else {
                    foreach ($socialProfiles as $name => $details) {
                        if ($integrationObject = $integrationHelper->getIntegrationObject($name)) {
                            if ($template = $integrationObject->getSocialProfileTemplate()) {
                                $integrations[$name]['newContent'] = $this->renderView(
                                    $template,
                                    [
                                        'lead'              => $lead,
                                        'details'           => $details,
                                        'integrationName'   => $name,
                                        'socialProfileUrls' => $socialProfileUrls,
                                    ]
                                );
                            }
                        }
                    }
                    $dataArray['profiles'] = $integrations;
                }

                $dataArray['success'] = 1;
            }
        }

        return $this->sendJsonResponse($dataArray);
    }

    /**
     * Clears the cache for a network.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    protected function clearSocialProfileAction(Request $request)
    {
        $dataArray = ['success' => 0];
        $network   = InputHelper::clean($request->request->get('network'));
        $leadId    = InputHelper::clean($request->request->get('lead'));

        if (!empty($leadId)) {
            //find the lead
            $model = $this->getModel('lead.lead');
            $lead  = $model->getEntity($leadId);

            if (null !== $lead && $this->get('mautic.security')->hasEntityAccess('lead:leads:editown', 'lead:leads:editown', $lead->getPermissionUser())) {
                $dataArray['success'] = 1;
                /** @var \Mautic\PluginBundle\Helper\IntegrationHelper $helper */
                $helper         = $this->factory->getHelper('integration');
                $socialProfiles = $helper->clearIntegrationCache($lead, $network);
                $socialCount    = count($socialProfiles);

                if (empty($socialCount)) {
                    $dataArray['completeProfile'] = $this->renderView(
                        'MauticLeadBundle:Social:index.html.php',
                        [
                            'socialProfiles'    => $socialProfiles,
                            'lead'              => $lead,
                            'socialProfileUrls' => $helper->getSocialProfileUrlRegex(false),
                        ]
                    );
                }

                $dataArray['socialCount'] = $socialCount;
            }
        }

        return $this->sendJsonResponse($dataArray);
    }

    /**
     * Updates the timeline events and gets returns updated HTML.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    protected function updateTimelineAction(Request $request)
    {
        $dataArray     = ['success' => 0];
        $includeEvents = InputHelper::clean($request->request->get('includeEvents', []));
        $excludeEvents = InputHelper::clean($request->request->get('excludeEvents', []));
        $search        = InputHelper::clean($request->request->get('search'));
        $leadId        = (int) $request->request->get('leadId');

        if (!empty($leadId)) {
            //find the lead
            $model = $this->getModel('lead.lead');
            $lead  = $model->getEntity($leadId);

            if (null !== $lead) {
                $session = $this->get('session');

                $filter = [
                    'search'        => $search,
                    'includeEvents' => $includeEvents,
                    'excludeEvents' => $excludeEvents,
                ];

                $session->set('mautic.lead.'.$leadId.'.timeline.filters', $filter);

                // Trigger the TIMELINE_ON_GENERATE event to fetch the timeline events from subscribed bundles
                $dispatcher = $this->dispatcher;
                $event      = new LeadTimelineEvent($lead, $filter);
                $dispatcher->dispatch(LeadEvents::TIMELINE_ON_GENERATE, $event);

                $events     = $event->getEvents();
                $eventTypes = $event->getEventTypes();

                $timeline = $this->renderView(
                    'MauticLeadBundle:Lead:history.html.php',
                    [
                        'events'       => $events,
                        'eventTypes'   => $eventTypes,
                        'eventFilters' => $filter,
                        'lead'         => $lead,
                    ]
                );

                $dataArray['success']      = 1;
                $dataArray['timeline']     = $timeline;
                $dataArray['historyCount'] = count($events);
            }
        }

        return $this->sendJsonResponse($dataArray);
    }

    /**
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    protected function toggleLeadListAction(Request $request)
    {
        $dataArray = ['success' => 0];
        $leadId    = (int) $request->request->get('leadId');
        $listId    = (int) $request->request->get('listId');
        $action    = InputHelper::clean($request->request->get('listAction'));

        if (!empty($leadId) && !empty($listId) && in_array($action, ['remove', 'add'])) {
            $leadModel = $this->getModel('lead');
            $listModel = $this->getModel('lead.list');

            $lead = $leadModel->getEntity($leadId);
            $list = $listModel->getEntity($listId);

            if (null !== $lead && null !== $list) {
                $class = 'add' == $action ? 'addToLists' : 'removeFromLists';
                $leadModel->$class($lead, $list);
                $dataArray['success'] = 1;
            }
        }

        return $this->sendJsonResponse($dataArray);
    }

    /**
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    protected function togglePreferredLeadChannelAction(Request $request)
    {
        $dataArray = ['success' => 0];
        $leadId    = (int) $request->request->get('leadId');
        $channel   = InputHelper::clean($request->request->get('channel'));
        $action    = InputHelper::clean($request->request->get('channelAction'));

        if (!empty($leadId) && !empty($channel) && in_array($action, ['remove', 'add'])) {
            $leadModel = $this->getModel('lead');
            /** @var DoNotContactModel $doNotContact */
            $doNotContact = $this->getModel('lead.dnc');

            $lead = $leadModel->getEntity($leadId);

            if (null !== $lead && null !== $channel) {
                if ('remove' === $action) {
                    $doNotContact->addDncForContact($leadId, $channel, DoNotContact::MANUAL, 'user');
                } elseif ('add' === $action) {
                    $doNotContact->removeDncForContact($leadId, $channel);
                }
                $dataArray['success'] = 1;
            }
        }

        return $this->sendJsonResponse($dataArray);
    }

    /**
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    protected function toggleLeadCampaignAction(Request $request)
    {
        $dataArray  = ['success' => 0];
        $leadId     = (int) $request->request->get('leadId');
        $campaignId = (int) $request->request->get('campaignId');
        $action     = InputHelper::clean($request->request->get('campaignAction'));

        if (empty($leadId) || empty($campaignId) || !in_array($action, ['remove', 'add'])) {
            return $this->sendJsonResponse($dataArray);
        }

        /** @var LeadModel $leadModel */
        $leadModel = $this->getModel('lead');

        /** @var CampaignModel $campaignModel */
        $campaignModel = $this->getModel('campaign');

        $lead     = $leadModel->getEntity($leadId);
        $campaign = $campaignModel->getEntity($campaignId);

        if (null === $lead || null === $campaign) {
            return $this->sendJsonResponse($dataArray);
        }

        /** @var MembershipManager $membershipManager */
        $membershipManager = $this->get('mautic.campaign.membership.manager');

        if ('add' === $action) {
            $membershipManager->addContact($lead, $campaign);
        }

        if ('remove' === $action) {
            $membershipManager->removeContact($lead, $campaign);
        }

        $dataArray['success'] = 1;

        return $this->sendJsonResponse($dataArray);
    }

    /**
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    protected function toggleCompanyLeadAction(Request $request)
    {
        $dataArray = ['success' => 0];
        $leadId    = (int) $request->request->get('leadId');
        $companyId = (int) $request->request->get('companyId');
        $action    = InputHelper::clean($request->request->get('companyAction'));

        if (!empty($leadId) && !empty($companyId) && in_array($action, ['remove', 'add'])) {
            $leadModel    = $this->getModel('lead');
            $companyModel = $this->getModel('lead.company');

            $lead    = $leadModel->getEntity($leadId);
            $company = $companyModel->getEntity($companyId);

            if (null !== $lead && null !== $company) {
                $class = 'add' == $action ? 'addLeadToCompany' : 'removeLeadFromCompany';
                $companyModel->$class($company, $lead);
                $dataArray['success'] = 1;
            }
        }

        return $this->sendJsonResponse($dataArray);
    }

    /**
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    protected function getImportProgressAction(Request $request)
    {
        $dataArray = ['success' => 1];

        if ($this->get('mautic.security')->isGranted('lead:leads:create')) {
            $session               = $this->get('session');
            $dataArray['progress'] = $session->get('mautic.lead.import.progress', [0, 0]);
            $dataArray['percent']  = ($dataArray['progress'][1]) ? ceil(($dataArray['progress'][0] / $dataArray['progress'][1]) * 100) : 100;
        }

        return $this->sendJsonResponse($dataArray);
    }

    /**
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    protected function removeBounceStatusAction(Request $request)
    {
        $dataArray = ['success' => 0];
        $dncId     = $request->request->get('id');

        if (!empty($dncId)) {
            /** @var \Mautic\LeadBundle\Model\LeadModel $model */

            /** @var DoNotContactModel $doNotContact */
            $doNotContact = $this->getModel('lead.dnc');

            /** @var DoNotContactModel $dnc */
            $dnc = $this->getDoctrine()->getManager()->getRepository('MauticLeadBundle:DoNotContact')->findOneBy(
                [
                    'id' => $dncId,
                ]
            );

            $lead = $dnc->getLead();
            if ($lead) {
                // Use lead model to trigger listeners
                $doNotContact->removeDncForContact($lead->getId(), 'email');
            } else {
                $this->getModel('email')->getRepository()->deleteDoNotEmailEntry($dncId);
            }

            $dataArray['success'] = 1;
        }

        return $this->sendJsonResponse($dataArray);
    }

    /**
     * Get the rows for new leads.
     *
     * @return array|\Symfony\Component\HttpFoundation\JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    protected function getNewLeadsAction(Request $request)
    {
        $dataArray = ['success' => 0];
        $maxId     = $request->get('maxId');

        if (!empty($maxId)) {
            //set some permissions
            $permissions = $this->get('mautic.security')->isGranted(
                [
                    'lead:leads:viewown',
                    'lead:leads:viewother',
                    'lead:leads:create',
                    'lead:leads:editown',
                    'lead:leads:editother',
                    'lead:leads:deleteown',
                    'lead:leads:deleteother',
                ],
                'RETURN_ARRAY'
            );

            if (!$permissions['lead:leads:viewown'] && !$permissions['lead:leads:viewother']) {
                return $this->accessDenied(true);
            }

            /** @var \Mautic\LeadBundle\Model\LeadModel $model */
            $model   = $this->getModel('lead.lead');
            $session = $this->get('session');

            $search = $session->get('mautic.lead.filter', '');

            $filter     = ['string' => $search, 'force' => []];
            $translator = $this->translator;
            $anonymous  = $translator->trans('mautic.lead.lead.searchcommand.isanonymous');
            $mine       = $translator->trans('mautic.core.searchcommand.ismine');
            $indexMode  = $session->get('mautic.lead.indexmode', 'list');

            $session->set('mautic.lead.indexmode', $indexMode);

            // (strpos($search, "$isCommand:$anonymous") === false && strpos($search, "$listCommand:") === false)) ||
            if ('list' != $indexMode) {
                //remove anonymous leads unless requested to prevent clutter
                $filter['force'][] = "!$anonymous";
            }

            if (!$permissions['lead:leads:viewother']) {
                $filter['force'][] = $mine;
            }

            $filter['force'][] = [
                'column' => 'l.id',
                'expr'   => 'gt',
                'value'  => $maxId,
            ];

            $results = $model->getEntities(
                [
                    'filter'         => $filter,
                    'withTotalCount' => true,
                ]
            );
            $count = $results['count'];

            if (!empty($count)) {
                // Get the max ID of the latest lead added
                $maxLeadId = $model->getRepository()->getMaxLeadId();

                // We need the EmailRepository to check if a lead is flagged as do not contact
                /** @var \Mautic\EmailBundle\Entity\EmailRepository $emailRepo */
                $emailRepo          = $this->getModel('email')->getRepository();
                $indexMode          = $this->request->get('view', $session->get('mautic.lead.indexmode', 'list'));
                $template           = ('list' == $indexMode) ? 'list_rows' : 'grid_cards';
                $dataArray['leads'] = $this->factory->getTemplating()->render(
                    "MauticLeadBundle:Lead:{$template}.html.php",
                    [
                        'items'         => $results['results'],
                        'noContactList' => $emailRepo->getDoNotEmailList(array_keys($results['results'])),
                        'permissions'   => $permissions,
                        'security'      => $this->get('mautic.security'),
                        'highlight'     => true,
                    ]
                );
                $dataArray['indexMode'] = $indexMode;
                $dataArray['maxId']     = $maxLeadId;
                $dataArray['success']   = 1;
            }
        }

        return $this->sendJsonResponse($dataArray);
    }

    /**
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    protected function getEmailTemplateAction(Request $request)
    {
        $data    = ['success' => 1, 'body' => '', 'subject' => ''];
        $emailId = $request->get('template');

        /** @var \Mautic\EmailBundle\Model\EmailModel $model */
        $model = $this->getModel('email');

        /** @var \Mautic\EmailBundle\Entity\Email $email */
        $email = $model->getEntity($emailId);

        if (null !== $email
            && $this->get('mautic.security')->hasEntityAccess(
                'email:emails:viewown',
                'email:emails:viewother',
                $email->getCreatedBy()
            )
        ) {
            $mailer = $this->factory->getMailer();
            $mailer->setEmail($email, true, [], [], true);

            $data['body']    = $mailer->getBody();
            $data['subject'] = $mailer->getSubject();
        }

        return $this->sendJsonResponse($data);
    }

    /**
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    protected function updateLeadTagsAction(Request $request)
    {
        /** @var \Mautic\LeadBundle\Model\LeadModel $leadModel */
        $leadModel   = $this->getModel('lead');
        $post        = $request->request->get('lead_tags', [], true);
        $lead        = $leadModel->getEntity((int) $post['id']);
        $updatedTags = (!empty($post['tags']) && is_array($post['tags'])) ? $post['tags'] : [];
        $data        = ['success' => 0];

        if (null !== $lead && $this->get('mautic.security')->hasEntityAccess('lead:leads:editown', 'lead:leads:editother', $lead->getPermissionUser())) {
            $leadModel->setTags($lead, $updatedTags, true);

            /** @var \Doctrine\ORM\PersistentCollection $leadTags */
            $leadTags    = $lead->getTags();
            $leadTagKeys = $leadTags->getKeys();

            // Get an updated list of tags
            $tags       = $leadModel->getTagRepository()->getSimpleList(null, [], 'tag');
            $tagOptions = '';

            foreach ($tags as $tag) {
                $selected = (in_array($tag['label'], $leadTagKeys)) ? ' selected="selected"' : '';
                $tagOptions .= '<option'.$selected.' value="'.$tag['value'].'">'.$tag['label'].'</option>';
            }

            $data['success'] = 1;
            $data['tags']    = $tagOptions;
        }

        return $this->sendJsonResponse($data);
    }

    /**
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    protected function addLeadTagsAction(Request $request)
    {
        $tags = $request->request->get('tags');
        $tags = json_decode($tags, true);

        if (is_array($tags)) {
            $leadModel = $this->getModel('lead');
            $newTags   = [];

            foreach ($tags as $tag) {
                if (!is_numeric($tag)) {
                    $newTags[] = $leadModel->getTagRepository()->getTagByNameOrCreateNewOne($tag);
                }
            }

            if (!empty($newTags)) {
                $leadModel->getTagRepository()->saveEntities($newTags);
            }

            // Get an updated list of tags
            $allTags    = $leadModel->getTagRepository()->getSimpleList(null, [], 'tag');
            $tagOptions = '';

            foreach ($allTags as $tag) {
                $selected = (in_array($tag['value'], $tags) || in_array($tag['label'], $tags)) ? ' selected="selected"' : '';
                $tagOptions .= '<option'.$selected.' value="'.$tag['value'].'">'.$tag['label'].'</option>';
            }

            $data = [
                'success' => 1,
                'tags'    => $tagOptions,
            ];
        } else {
            $data = ['success' => 0];
        }

        return $this->sendJsonResponse($data);
    }

    /**
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    protected function addLeadUtmTagsAction(Request $request)
    {
        $utmTags = $request->request->get('utmtags');
        $utmTags = json_decode($utmTags, true);

        if (is_array($utmTags)) {
            $newUtmTags = [];
            foreach ($utmTags as $utmTag) {
                if (!is_numeric($utmTag)) {
                    // New tag
                    $utmTagEntity = new UtmTag();
                    $utmTagEntity->setUtmTag(InputHelper::clean($utmTag));
                    $newUtmTags[] = $utmTagEntity;
                }
            }

            $leadModel = $this->getModel('lead');

            if (!empty($newUtmTags)) {
                $leadModel->getUtmTagRepository()->saveEntities($newUtmTags);
            }

            // Get an updated list of tags
            $allUtmTags    = $leadModel->getUtmTagRepository()->getSimpleList(null, [], 'utmtag');
            $utmTagOptions = '';

            foreach ($allUtmTags as $utmTag) {
                $selected = (in_array($utmTag['value'], $utmTags) || in_array($utmTag['label'], $utmTags)) ? ' selected="selected"' : '';
                $utmTagOptions .= '<option'.$selected.' value="'.$utmTag['value'].'">'.$utmTag['label'].'</option>';
            }

            $data = [
                'success' => 1,
                'tags'    => $utmTagOptions,
            ];
        } else {
            $data = ['success' => 0];
        }

        return $this->sendJsonResponse($data);
    }

    /**
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    protected function reorderAction(Request $request)
    {
        $dataArray = ['success' => 0];
        $order     = InputHelper::clean($request->request->get('field'));
        $page      = (int) $request->get('page');
        $limit     = (int) $request->get('limit');

        if (!empty($order)) {
            /** @var \Mautic\LeadBundle\Model\FieldModel $model */
            $model = $this->getModel('lead.field');

            $startAt = ($page > 1) ? ($page * $limit) + 1 : 1;
            $model->reorderFieldsByList($order, $startAt);
            $dataArray['success'] = 1;
        }

        return $this->sendJsonResponse($dataArray);
    }

    /**
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    protected function updateLeadFieldValuesAction(Request $request)
    {
        $alias     = InputHelper::clean($request->request->get('alias'));
        $operator  = InputHelper::clean($request->request->get('operator'));
        $changed   = InputHelper::clean($request->request->get('changed'));
        $dataArray = ['success' => 0, 'options' => null, 'optionsAttr' => [], 'operators' => null, 'disabled' => false];
        $leadField = $this->getModel('lead.field')->getRepository()->findOneBy(['alias' => $alias]);

        if ($leadField) {
            $options       = null;
            $leadFieldType = $leadField->getType();

            $properties = $leadField->getProperties();
            if (!empty($properties['list'])) {
                // Lookup/Select options
                $options = FormFieldHelper::parseList($properties['list']);
            } elseif (!empty($properties) && 'boolean' == $leadFieldType) {
                // Boolean options
                $options = [
                    0 => $properties['no'],
                    1 => $properties['yes'],
                ];
            } else {
                switch ($leadFieldType) {
                    case 'country':
                        $options = FormFieldHelper::getCountryChoices();
                        break;
                    case 'region':
                        $options = FormFieldHelper::getRegionChoices();
                        break;
                    case 'timezone':
                        $options = FormFieldHelper::getTimezonesChoices();
                        break;
                    case 'locale':
                        $options = FormFieldHelper::getLocaleChoices();
                        break;
                    case 'date':
                    case 'datetime':
                        if ('date' == $operator) {
                            $fieldHelper = new FormFieldHelper();
                            $fieldHelper->setTranslator($this->get('translator'));
                            $options = $fieldHelper->getDateChoices();
                            $options = array_merge(
                                [
                                    'custom' => $this->translator->trans('mautic.campaign.event.timed.choice.custom'),
                                ],
                                $options
                            );

                            $dataArray['optionsAttr']['custom'] = [
                                'data-custom' => 1,
                            ];
                        }
                        break;
                    default:
                        $options = (!empty($properties)) ? $properties : [];
                }
            }

            $dataArray['fieldType'] = $leadFieldType;
            $dataArray['options']   = $options;

            if ('field' === $changed) {
                $dataArray['operators'] = $this->getModel('lead')->getOperatorsForFieldType($leadFieldType, ['date']);
                foreach ($dataArray['operators'] as $value => $label) {
                    $dataArray['operators'][$value] = $this->get('translator')->trans($label);
                }

                reset($dataArray['operators']);
                $operator = key($dataArray['operators']);
            }

            $disabled = false;
            switch ($operator) {
                case 'empty':
                case '!empty':
                    $disabled             = true;
                    $dataArray['options'] = null;
                    break;
                case 'regexp':
                case '!regexp':
                    $dataArray['options'] = null;
                    break;
            }
            $dataArray['disabled'] = $disabled;
        }

        $dataArray['success'] = 1;

        return $this->sendJsonResponse($dataArray);
    }

    /**
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    protected function setAsPrimaryCompanyAction(Request $request)
    {
        $dataArray['success'] = 1;
        $companyId            = InputHelper::clean($request->request->get('companyId'));
        $leadId               = InputHelper::clean($request->request->get('leadId'));

        $leadModel      = $this->getModel('lead');
        $primaryCompany = $leadModel->setPrimaryCompany($companyId, $leadId);

        $dataArray = array_merge($dataArray, $primaryCompany);

        return $this->sendJsonResponse($dataArray);
    }

    protected function getCampaignShareStatsAction(Request $request)
    {
        $ids      = $request->get('ids');
        $entityid = $request->get('entityId');
        /** @var SegmentCampaignShare $segmentCampaignShareService */
        $segmentCampaignShareService = $this->get('mautic.lead.segment.stat.campaign.share');

        $data = $segmentCampaignShareService->getCampaignsSegmentShare($entityid, $ids);

        $data = [
            'success' => 1,
            'stats'   => $data,
        ];

        return new JsonResponse($data);
    }
}
