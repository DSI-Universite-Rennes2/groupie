<?php

namespace Amu\CliGrouperBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

class UserSearchType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
             ->add('uid', 'text', array('label' => 'Identifiant (uid)', 'required' => 'false'))
             ->add('sn', 'text', array('label' => 'Nom', 'required' => 'false')); 

    }
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults(array(
                                     'data_class' =>  'Amu\CliGrouperBundle\Entity\User'
                                     ));
    }
    public function getName()
    {
        return 'usersearch';
    }
}