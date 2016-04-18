<?php

namespace Amu\GroupieBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

class GroupEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
                
        $builder
                 ->add('members', 'collection', array('type' => new MemberType()));

    }
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults(array(
                                     'data_class' => 'Amu\GroupieBundle\Entity\Group',
                                      'attr' => ['id' => 'groupedit']
                                     ));
    }
    public function getName()
    {
        return 'groupedit';
    }
}