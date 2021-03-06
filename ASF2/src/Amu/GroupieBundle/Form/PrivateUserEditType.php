<?php

namespace Amu\GroupieBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

class PrivateUserEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
                
        $builder
                 ->add('memberships', 'collection', array('type' => new PrivateMembershipType())
                 );

    }
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults(array(
                                     'data_class' => 'Amu\GroupieBundle\Entity\User'
                                     ));
    }
    public function getName()
    {
        return 'useredit';
    }
}