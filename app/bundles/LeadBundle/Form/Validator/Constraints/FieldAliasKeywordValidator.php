<?php

namespace Mautic\LeadBundle\Form\Validator\Constraints;

use Doctrine\ORM\EntityManager;
use Mautic\LeadBundle\Entity\LeadField;
use Mautic\LeadBundle\Helper\FieldAliasHelper;
use Mautic\LeadBundle\Model\ListModel;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Throws an exception if the field alias is equal some segment filter keyword.
 * It would cause odd behavior with segment filters otherwise.
 */
class FieldAliasKeywordValidator extends ConstraintValidator
{
    /**
     * @var ListModel
     */
    private $listModel;

    /**
     * @var FieldAliasHelper
     */
    private $aliasHelper;

    /**
     * @var EntityManager
     */
    private $em;

    public function __construct(ListModel $listModel, FieldAliasHelper $aliasHelper, EntityManager $em)
    {
        $this->listModel   = $listModel;
        $this->aliasHelper = $aliasHelper;
        $this->em          = $em;
    }

    /**
     * @param LeadField $field
     */
    public function validate($field, Constraint $constraint)
    {
        $oldValue = $this->em->getUnitOfWork()->getOriginalEntityData($field);
        $this->aliasHelper->makeAliasUnique($field);

        //If empty it's a new object else it's an edit
        if (empty($oldValue) || (!empty($oldValue) && is_array($oldValue) && $oldValue['alias'] != $field->getAlias())) {
            $segmentChoices = $this->listModel->getChoiceFields();
            if (isset($segmentChoices[$field->getObject()][$field->getAlias()])) {
                $this->context->addViolation($constraint->message, ['%keyword%' => $field->getAlias()]);
            }
        }
    }
}
