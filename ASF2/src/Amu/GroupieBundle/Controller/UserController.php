<?php

namespace Amu\GroupieBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\Common\Collections\ArrayCollection;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Amu\GroupieBundle\Entity\Group;
use Amu\GroupieBundle\Entity\User;
use Amu\GroupieBundle\Form\UserEditType;
use Amu\GroupieBundle\Form\PrivateUserEditType;
use Amu\GroupieBundle\Form\UserSearchType;
use Amu\GroupieBundle\Form\UserMultipleType;
use Amu\GroupieBundle\Entity\Membership;
use Amu\GroupieBundle\Entity\Member;
use Amu\GroupieBundle\Form\PrivateGroupEditType;
use Amu\GroupieBundle\Form\GroupEditType;

use Amu\GroupieBundle\Controller\GroupController;


/**
 * user controller
 * @Route("/user")
 * 
 */
class UserController extends Controller {
    

     /**
     * Affiche l'utilisateur courant.
     *
     * @Route("/", name="user")
     * @Template()
     */
    public function indexAction(Request $request)
    {
        $searchForm = $this->createForm(
            new UserSearchType(), null, array(
                                                  'action' => $this->generateUrl('user'),
                                                  'method' => 'POST',
                                                  )
        );
        
        $userSearchForm = $request->get('usersearch');
        $uid = $userSearchForm['uid'];
        
        $users = array();
                   
        $searchForm->handleRequest($request);

        if ($searchForm->isSubmitted()) {

            // Recherche des utilisateurs dans le LDAP
            $arData=$this->getLdap()->arDatasFilter("uid=".$uid, array('uid', 'sn','displayname', 'mail', 'telephonenumber', 'memberof'));
            
            // Test de la validité de l'uid
            if ($arData[0]['uid'][0] == '') {
                $this->get('session')->getFlashBag()->add('flash-notice', 'L\'uid n\'est pas valide');
                $this->getRequest()->getSession()->set('_saved', 0);
            }
            else {
                $user = new User();
                $user->setUid($arData[0]['uid'][0]);
                $user->setDisplayname($arData[0]['displayname'][0]);
                $user->setMail($arData[0]['mail'][0]);
                $user->setSn($arData[0]['sn'][0]);
                $user->setTel($arData[0]['telephonenumber'][0]);
                // Récupération du cn des groupes (memberof)
                $tab = array_splice($arData[0]['memberof'], 1);
                $tab_cn = array();
                foreach($tab as $dn)
                    $tab_cn[] = preg_replace("/(cn=)(([A-Za-z0-9:_-]{1,}))(,ou=.*)/", "$3", $dn);
                
                $user->setMemberof($tab_cn); 
        
                $users[] = $user;
            
                $this->getRequest()->getSession()->set('_saved',1);
            }
        }
        else {
            $this->getRequest()->getSession()->set('_saved', 0);
            
        }
        
        return array(
            'users' => $users,
            'form' => $searchForm->createView()
             );
    }
        
    /**
     * Edite les droits d'un utilisateur issu du LDAP.
     *
     * @Route("/update/{uid}", name="user_update")
     * @Template("AmuGroupieBundle:User:edit.html.twig")
     */
    public function updateAction(Request $request, $uid)
    {
        // On récupère le service ldapfonctions
        $ldapfonctions = $this->container->get('groupie.ldapfonctions');
        $ldapfonctions->SetLdap($this->get('amu.ldap'));

        // Dans le cas d'un gestionnaire
        if (true === $this->get('security.context')->isGranted('ROLE_GESTIONNAIRE')) {
            // Recup des groupes dont l'utilisateur est admin
            $arDataAdminLogin = $ldapfonctions->recherche("amuGroupAdmin=uid=".$request->getSession()->get('phpCAS_user').",ou=people,dc=univ-amu,dc=fr",array("cn", "description", "amugroupfilter"), "cn");
            for($i=0;$i<$arDataAdminLogin["count"];$i++)
            {
                $tab_cn_admin_login[$i] = $arDataAdminLogin[$i]["cn"][0];
            }
        }
        
        // Recherche des utilisateurs dans le LDAP
        $arData = $ldapfonctions->recherche("uid=".$uid, array('uid', 'sn','displayname', 'mail', 'telephonenumber', 'memberof'), "uid");
            
        // Initialisation de l'utilisateur sur lequel on souhaite modifier les appartenances
        $user = new User();
        $user->setUid($uid);
        $user->setDisplayname($arData[0]['displayname'][0]);
        $user->setMail($arData[0]['mail'][0]);
        $user->setSn($arData[0]['sn'][0]);
        $user->setTel($arData[0]['telephonenumber'][0]);
        $tab = array_splice($arData[0]['memberof'], 1);
        $tab_cn = array(); 
        $nb_public=0;
        foreach($tab as $dn) {
            // on ne récupère que les groupes publics
            if (!strstr($dn, "ou=private")) {
                $tab_cn[] = preg_replace("/(cn=)(([A-Za-z0-9:_-]{1,}))(,ou=.*)/", "$3", $dn);
                $nb_public++;
            }
        }
        $user->setMemberof($tab_cn); 
        
        // User initial pour détecter les modifications
        $userini = new User();
        $userini->setUid($uid);
        $userini->setDisplayname($arData[0]['displayname'][0]);
        $userini->setMail($arData[0]['mail'][0]);
        $userini->setSn($arData[0]['sn'][0]);
        $userini->setTel($arData[0]['telephonenumber'][0]);
        $userini->setMemberof($tab_cn); 
        
        // Récupération des groupes dont l'utilisateur est admin
        $arDataAdmin = $ldapfonctions->recherche("amuGroupAdmin=uid=".$uid.",ou=people,dc=univ-amu,dc=fr",array("cn", "description", "amugroupfilter"), "cn");
        $flagMember = array();
        for($i=0;$i<$arDataAdmin["count"];$i++)
            $flagMember[$i] = FALSE;
        
        // Initialisation des tableaux d'entités
        $groups = new ArrayCollection();
        $memberships = new ArrayCollection();
        $membershipsini = new ArrayCollection();
        
        // Gestion des groupes publics dont l'utilisateur est membre
        for($i=0; $i<$nb_public;$i++){
            $membership = new Membership();
            $membership->setGroupname($tab_cn[$i]);
            $membership->setMemberof(TRUE);
            $membership->setDroits('Aucun');
            
            // Idem pour membershipini
            $membershipini = new Membership();
            $membershipini->setGroupname($tab_cn[$i]);
            $membershipini->setMemberof(TRUE);
            $membershipini->setDroits('Aucun'); 
            // on teste si l'utilisateur est aussi admin du groupe
            for ($j=0; $j<$arDataAdmin["count"];$j++) {
                if ($arDataAdmin[$j]["cn"][0] == $tab_cn[$i]) {
                    $membership->setAdminof(TRUE);
                    $membershipini->setAdminof(TRUE);
                    $flagMember[$j] = TRUE;
                    break;
                }
                else {
                    $membership->setAdminof(FALSE);
                    $membershipini->setAdminof(FALSE);
                }
            }
            
            // Par défaut
            $membership->setDroits('Aucun');
            $membershipini->setDroits('Aucun'); 
                            
            // Gestion droits pour un membre de la DOSI
            if (true === $this->get('security.context')->isGranted('ROLE_DOSI')) {
                $membership->setDroits('Voir');
                $membershipini->setDroits('Voir');  
            }
            
            // Gestion droits pour un gestionnaire
            if (true === $this->get('security.context')->isGranted('ROLE_GESTIONNAIRE')) {
                foreach($tab_cn_admin_login as $cn) {
                    if ($cn==$tab_cn[$i]) {
                        $membership->setDroits('Modifier');
                        $membershipini->setDroits('Modifier');
                        break;
                    }
                }
            }
            
            // Gestion droits pour un admin de l'appli
            if (true === $this->get('security.context')->isGranted('ROLE_ADMIN')) {
                $membership->setDroits('Modifier');
                $membershipini->setDroits('Modifier');  
            }
            
            $memberships[$i] = $membership;
            $membershipsini[$i] = $membershipini;
        }
        
        // Gestion des groupes dont l'utilisateur est seulement admin
        for($i=0;$i<$arDataAdmin["count"];$i++) {
            if ($flagMember[$i]==FALSE) {
                // on ajoute le groupe pour l'utilisateur
                $membership = new Membership();
                $membership->setGroupname($arDataAdmin[$i]["cn"][0]);
                $membership->setMemberof(FALSE);
                $membership->setAdminof(TRUE);
                $membership->setDroits('Aucun');
                
                // Idem pour membershipini
                $membershipini = new Membership();
                $membershipini->setGroupname($arDataAdmin[$i]["cn"][0]);
                $membershipini->setMemberof(FALSE);
                $membershipini->setAdminof(TRUE);
                $membershipini->setDroits('Aucun');
                
                // Gestion droits pour un membre de la DOSI
                if (true === $this->get('security.context')->isGranted('ROLE_DOSI')) {
                    $membership->setDroits('Voir');
                    $membershipini->setDroits('Voir');  
                }
            
                // Gestion droits pour un gestionnaire
                if (true === $this->get('security.context')->isGranted('ROLE_GESTIONNAIRE')) {
                    foreach($tab_cn_admin_login as $cn) {
                        if ($cn==$arDataAdmin[$i]["cn"][0]) {
                            $membership->setDroits('Modifier');
                            $membershipini->setDroits('Modifier');
                            break;
                        }
                    }
                }
                // Gestion droits pour un admin de l'appli
                if (true === $this->get('security.context')->isGranted('ROLE_ADMIN')) {
                    $membership->setDroits('Modifier');
                    $membershipini->setDroits('Modifier');
                }
            
                $memberships[] = $membership;
                $membershipsini[] = $membershipini;
            }
            
        }
        
        $user->setMemberships($memberships);
        $userini->setMemberships($membershipsini);
                                
        // Création du formulaire de mise à jour de l'utilisateur
        $editForm = $this->createForm(new UserEditType(), $user);
        $editForm->handleRequest($request);
        if ($editForm->isValid()) {
            $userupdate = new User();
            // Récupération des données du formulaire
            $userupdate = $editForm->getData();
             
            // Log modif de groupe
            openlog("groupie", LOG_PID | LOG_PERROR, LOG_LOCAL0);
            $adm = $request->getSession()->get('phpCAS_user');
            
            // Traitement des données issues de la récup du formulaire
            $m_update = new ArrayCollection();      
            $m_update = $userupdate->getMemberships();
            for ($i=0; $i<sizeof($m_update); $i++) {
                $memb = $m_update[$i];
                $dn_group = "cn=" . $memb->getGroupname() . ", ou=groups, dc=univ-amu, dc=fr";
                $c = $memb->getGroupname();
                
                // Si l'utilisateur logué à les droits en modification
                if ($memb->getDroits()=='Modifier') {
                    // Si changement, on modifie dans le ldap
                    if ($memb->getMemberof() != $membershipsini[$i]->getMemberof()) {
                        if ($memb->getMemberof()) {
                            $r = $this->getLdap()->addMemberGroup($dn_group, array($uid));
                            if ($r) {
                                // Log modif
                                syslog(LOG_INFO, "add_member by $adm : group : $c, user : $uid");
                            }
                            else {
                                // Log erreur
                                syslog(LOG_ERR, "LDAP ERROR : add_member by $adm : group : $c, user : $uid");
                            }              
                        }
                        else {
                            $r = $this->getLdap()->delMemberGroup($dn_group, array($uid));
                            if ($r) {
                                // Log modif
                                syslog(LOG_INFO, "del_member by $adm : group : $c, user : $uid");
                            }
                            else {
                                syslog(LOG_ERR, "LDAP ERROR : del_member by $adm : group : $c, user : $uid");
                            }
                        }
                    }
                    // Traitement des admins
                    // Idem si changement des droits
                    if ($memb->getAdminof() != $membershipsini[$i]->getAdminof()) {
                        if ($memb->getAdminof()) {
                            $r = $this->getLdap()->addAdminGroup($dn_group, array($uid));
                            if ($r) {
                                // Log modif
                                syslog(LOG_INFO, "add_admin by $adm : group : $c, user : $uid");
                            }
                            else {
                                syslog(LOG_ERR, "LDAP ERROR : add_admin by $adm : group : $c, user : $uid");
                            }
                        }
                        else {
                            $r = $this->getLdap()->delAdminGroup($dn_group, array($uid));
                            if ($r) {
                                // Log modif
                                syslog(LOG_INFO, "del_admin by $adm : group : $c, user : $uid");
                            }
                            else {
                                syslog(LOG_ERR, "LDAP ERROR : del_admin by $adm : group : $c, user : $uid");
                            }
                        }
                    }
                }
            }
            // Ferme fichier log
            closelog();
            
            // Afiichage du message de notification
            $this->get('session')->getFlashBag()->add('flash-notice', 'Les modifications ont bien été enregistrées');
            $this->getRequest()->getSession()->set('_saved',1);
            
            // Retour à l'affichage user_update
            return $this->redirect($this->generateUrl('user_update', array('uid'=>$uid)));
        }
        else {
            $this->getRequest()->getSession()->set('_saved',0);
        }

        return array(
            'user'      => $user,
            'form'   => $editForm->createView(),
        );
    }
  
     
    /**
     * Ajoute les droits d'un utilisateur à un groupe.
     *
     * @Route("/add/{uid}/{cn}/{liste}",name="user_add")
     * @Template("AmuGroupieBundle:User:searchadd.html.twig")
     */
    public function addAction(Request $request, $uid='', $cn='', $liste='') {
        // Récupération utilisateur
        $user = new User();
        $user->setUid($uid);

        // On récupère le service ldapfonctions
        $ldapfonctions = $this->container->get('groupie.ldapfonctions');
        $ldapfonctions->SetLdap($this->get('amu.ldap'));

        $arDataUser = $ldapfonctions->recherche("uid=".$uid, array('displayname', 'memberof', 'uid'), "uid");
                
        // Test de la validité de l'uid
        if ($arDataUser[0]['uid'][0] == '') {
            $this->get('session')->getFlashBag()->add('flash-notice', 'L\'uid n\'est pas valide');
            $this->getRequest()->getSession()->set('_saved', 0);
            return $this->redirect($this->generateUrl('user_search', array('opt' => 'add', 'cn'=>$cn)));
        }
        else {
            $user->setDisplayname($arDataUser[0]['displayname'][0]);
            $tab = array_splice($arDataUser[0]['memberof'], 1);
            // Tableau des groupes de l'utilisateur
            $tab_cn = array();
            foreach($tab as $dn)
                $tab_cn[] = preg_replace("/(cn=)(([A-Za-z0-9:._-]{1,}))(,ou=.*)/", "$3", $dn);
    
            // Recherche des admins du groupe dans le LDAP
            $arAdmins = $ldapfonctions->getAdminsGroup($cn);

            // User initial pour détecter les modifications
            $userini = new User();
            $userini->setUid($uid);
            $userini->setDisplayname($arDataUser[0]['displayname'][0]);

            // on remplit l'objet user avec les droits courants sur le groupe
            $memberships = new ArrayCollection();
            $membership = new Membership();
            $membership->setGroupname($cn);

            // Idem pour userini
            $membershipsini = new ArrayCollection();
            $membershipini = new Membership();
            $membershipini->setGroupname($cn);

            // Droits "membre"
            foreach($tab_cn as $cn_g) {
                if ($cn==$cn_g) {
                    $membership->setMemberof(TRUE);
                    $membershipini->setMemberof(TRUE);
                    break;
                }
                else {
                    $membership->setMemberof(FALSE);
                    $membershipini->setMemberof(FALSE);
                }
            }
            // Droits "admin"
            for ($j=0; $j<$arAdmins[0]["amugroupadmin"]["count"]; $j++) {       
                // récupération des uid des admin du groupe
                $uid_admins = preg_replace("/(uid=)(([A-Za-z0-9:._-]{1,}))(,ou=.*)/", "$3", $arAdmins[0]["amugroupadmin"][$j]);
                if ($uid == $uid_admins) {
                    $membership->setAdminof(TRUE);
                    $membershipini->setAdminof(TRUE);
                    break;
                }
                else {
                    $membership->setAdminof(FALSE);
                    $membershipini->setAdminof(FALSE);
                }
            }
            $memberships[0] = $membership;
            $user->setMemberships($memberships);       

            // Idem userini
            $membershipsini[0] = $membershipini;
            $userini->setMemberships($membershipsini);       

            // Création du formulaire d'ajout
            $editForm = $this->createForm(new UserEditType(), $user, array(
                'action' => $this->generateUrl('user_add', array('uid'=> $uid, 'cn' => $cn)),
                'method' => 'GET',
            ));
            $editForm->handleRequest($request);
            if ($editForm->isValid()) {
                $userupdate = new User();
                // Récupération des données du formulaire
                $userupdate = $editForm->getData();

                // Log modif de groupe
                openlog("groupie", LOG_PID | LOG_PERROR, LOG_SYSLOG);
                $adm = $request->getSession()->get('phpCAS_user');

                $m_update = new ArrayCollection();      
                $m_update = $userupdate->getMemberships();

                for ($i=0; $i<sizeof($m_update); $i++) {
                    $memb = $m_update[$i];
                    $dn_group = "cn=" . $cn . ", ou=groups, dc=univ-amu, dc=fr";

                    // Traitement des membres
                    // Si modification des droits, on modifie dans le ldap
                    if ($memb->getMemberof() != $membershipsini[$i]->getMemberof()) {
                        if ($memb->getMemberof()) {
                            $r = $ldapfonctions->addMemberGroup($dn_group, array($uid));
                            if ($r) {
                                // Log modif
                                syslog(LOG_INFO, "add_member by $adm : group : $cn, user : $uid");
                            }
                            else {
                                syslog(LOG_ERR, "LDAP ERROR : add_member by $adm : group : $cn, user : $uid");
                            }
                        }
                        else {
                            $r = $ldapfonctions->delMemberGroup($dn_group, array($uid));
                            if ($r) {
                                // Log modif
                                syslog(LOG_INFO, "del_member by $adm : group : $cn, user : $uid");
                            }
                            else {
                                syslog(LOG_ERR, "LDAP ERROR : del_member by $adm : group : $cn, user : $uid");
                            }
                        }
                    }

                    // Traitement des admins
                    // Si modification des droits, on modifie dans le ldap
                    if ($memb->getAdminof() != $membershipsini[$i]->getAdminof()) {
                        if ($memb->getAdminof()) {
                            $r = $ldapfonctions->addAdminGroup($dn_group, array($uid));
                            if ($r) {
                                // Log modif
                                syslog(LOG_INFO, "add_admin by $adm : group : $cn, user : $uid");
                            }
                            else {
                                syslog(LOG_ERR, "LDAP ERROR : add_admin by $adm : group : $cn, user : $uid");
                            }
                        }
                        else {
                            $r = $ldapfonctions->delAdminGroup($dn_group, array($uid));
                            if ($r) {
                                // Log modif
                                syslog(LOG_INFO, "del_admin by $adm : group : $cn, user : $uid");
                            }
                            else {
                                syslog(LOG_ERR, "LDAP ERROR : del_admin by $adm : group : $cn, user : $uid");
                            }
                        }
                    }
                }
                // Ferme fichier log
                closelog();

                // Affichage notification
                $this->get('session')->getFlashBag()->add('flash-notice', 'Les droits ont bien été ajoutés');
                $this->getRequest()->getSession()->set('_saved',1);
                
                // Récupération du nouveau groupe modifié pour affichage
                $newgroup = new Group();
                $newgroup->setCn($cn);
                $newmembers = new ArrayCollection();

                // Recherche des membres dans le LDAP
                $narUsers = $ldapfonctions->getMembersGroup($cn);

                // Recherche des admins dans le LDAP
                $narAdmins = $ldapfonctions->getAdminsGroup($cn);
                $nflagMembers = array();
                for($i=0;$i<$narAdmins[0]["amugroupadmin"]["count"];$i++)
                    $nflagMembers[] = FALSE;
                
                // Affichage des membres  
                for ($i=0; $i<$narUsers["count"]; $i++) {                     
                    $newmembers[$i] = new Member();
                    $newmembers[$i]->setUid($narUsers[$i]["uid"][0]);
                    $newmembers[$i]->setDisplayname($narUsers[$i]["displayname"][0]);
                    $newmembers[$i]->setMail($narUsers[$i]["mail"][0]);
                    $newmembers[$i]->setTel($narUsers[$i]["telephonenumber"][0]);
                    $newmembers[$i]->setMember(TRUE); 
                    $newmembers[$i]->setAdmin(FALSE);

                    // on teste si le membre est aussi admin
                    for ($j=0; $j<$narAdmins[0]["amugroupadmin"]["count"]; $j++) {
                        $uid = preg_replace("/(uid=)(([A-Za-z0-9:._-]{1,}))(,ou=.*)/", "$3", $narAdmins[0]["amugroupadmin"][$j]);
                        if ($uid==$narUsers[$i]["uid"][0]) {
                            $newmembers[$i]->setAdmin(TRUE);
                            $nflagMembers[$j] = TRUE;
                            break;
                        }
                    }
                }
                // Affichage des admins qui ne sont pas membres
                for ($j=0; $j<$narAdmins[0]["amugroupadmin"]["count"]; $j++) {       
                    if ($nflagMembers[$j]==FALSE)  {
                        // si l'admin n'est pas membre du groupe, il faut aller récupérer ses infos dans le LDAP
                        $uid = preg_replace("/(uid=)(([A-Za-z0-9:._-]{1,}))(,ou=.*)/", "$3", $narAdmins[0]["amugroupadmin"][$j]);
                        $result = $ldapfonctions->getInfosUser($uid);

                        $nmemb = new Member();
                        $nmemb->setUid($result[0]["uid"][0]);
                        $nmemb->setDisplayname($result[0]["displayname"][0]);
                        $nmemb->setMail($result[0]["mail"][0]);
                        $nmemb->setTel($result[0]["telephonenumber"][0]);
                        $nmemb->setMember(FALSE);
                        $nmemb->setAdmin(TRUE);
                        $newmembers[] = $nmemb;
                    }
                }

                $newgroup ->setMembers($newmembers);

                // Création formulaire de mise à jour de l'utilisateur
                $editForm = $this->createForm(new GroupEditType(), $newgroup);
                
                return $this->render('AmuGroupieBundle:Group:update.html.twig', array('group' => $newgroup, 'nb_membres' => $narUsers["count"], 'form' => $editForm->createView(), 'liste' => $liste));
            }
            else { 
                $this->getRequest()->getSession()->set('_saved',0);
            }
        }
        
        return array(
            'user'      => $user,
            'cn' => $cn,
            'form'   => $editForm->createView(),
            'liste' => $liste
        );
    }
     
    /**
     * Ajoute les droits d'un utilisateur à un groupe.
     *
     * @Route("/addprivate/{uid}/{cn}/{opt}",name="user_add_private")
     * @Template("AmuGroupieBundle:User:searchaddprivate.html.twig")
     */
    public function addprivateAction(Request $request, $uid='', $cn='', $opt='liste') {
        // Récupération utilisateur
        $user = new User();
        $user->setUid($uid);
        $arDataUser=$this->getLdap()->arDatasFilter("uid=".$uid, array('displayname', 'memberof', 'uid')); 
        
        // Test de la validité de l'uid
        if ($arDataUser[0]['uid'][0] == '') {
            $this->get('session')->getFlashBag()->add('flash-notice', 'L\'uid n\'est pas valide');
            $this->getRequest()->getSession()->set('_saved', 0);
            return $this->redirect($this->generateUrl('user_search', array('opt' => 'addprivate', 'cn'=>$cn)));
        }
        else {
            $user->setDisplayname($arDataUser[0]['displayname'][0]);
            $tab = array_splice($arDataUser[0]['memberof'], 1);
            // Tableau des groupes de l'utilisateur
            $tab_cn = array();
            foreach($tab as $dn)
                $tab_cn[] = preg_replace("/(cn=)(([A-Za-z0-9:._-]{1,}))(,ou=.*)/", "$3", $dn);         

            // User initial pour détecter les modifications
            $userini = new User();
            $userini->setUid($uid);
            $userini->setDisplayname($arDataUser[0]['displayname'][0]);

            // on remplit l'objet user avec les droits courants sur le groupe
            $memberships = new ArrayCollection();
            $membership = new Membership();
            $membership->setGroupname($cn);

            // Idem pour userini
            $membershipsini = new ArrayCollection();
            $membershipini = new Membership();
            $membershipini->setGroupname($cn);

            // Droits "membre"
            foreach($tab_cn as $cn_g) {
                if ($cn==$cn_g) {
                    $membership->setMemberof(TRUE);
                    $membershipini->setMemberof(TRUE);
                    break;
                }
                else {
                    $membership->setMemberof(FALSE);
                    $membershipini->setMemberof(FALSE);
                }
            }
            
            $memberships[0] = $membership;
            $user->setMemberships($memberships);       

            // Idem userini
            $membershipsini[0] = $membershipini;
            $userini->setMemberships($membershipsini);       

            // Création formulaire de mise à jour
            $editForm = $this->createForm(new PrivateUserEditType(), $user, array(
                'action' => $this->generateUrl('user_add_private', array('uid'=> $uid, 'cn' => $cn, 'opt' => $opt)),
                'method' => 'POST',
            ));
            $editForm->handleRequest($request);

            if ($editForm->isValid()) {
                $userupdate = new User();
                // Récupération des données du formulaire
                $userupdate = $editForm->getData();

                // Log modif de groupe
                openlog("groupie", LOG_PID | LOG_PERROR, LOG_LOCAL0);
                $adm = $request->getSession()->get('login');

                $m_update = new ArrayCollection();      
                $m_update = $userupdate->getMemberships();

                // On parcourt les groupes
                for ($i=0; $i<sizeof($m_update); $i++) {
                    $memb = $m_update[$i];
                    $dn_group = "cn=" . $cn . ", ou=private, ou=groups, dc=univ-amu, dc=fr";

                    // Traitement des membres
                    // Si modification des droits, on modifie dans le ldap
                    if ($memb->getMemberof() != $membershipsini[$i]->getMemberof()) {
                        if ($memb->getMemberof()) {
                            $r = $this->getLdap()->addMemberGroup($dn_group, array($uid));
                            if ($r) {
                                // Log modif
                                syslog(LOG_INFO, "add_member by $adm : group : $cn, user : $uid");
                            }
                            else {
                                syslog(LOG_ERR, "LDAP ERROR : add_member by $adm : group : $cn, user : $uid");
                            }
                        }
                        else {
                            $r = $this->getLdap()->delMemberGroup($dn_group, array($uid));
                            if ($r) {
                                // Log modif
                                syslog(LOG_INFO, "del_member by $adm : group : $cn, user : $uid");
                            }
                            else {
                                syslog(LOG_ERR, "LDAP ERROR : del_member by $adm : group : $cn, user : $uid");
                            }
                        }
                    }
                }
                // Ferme fichier log
                closelog();

                $this->get('session')->getFlashBag()->add('flash-notice', 'Les droits ont bien été ajoutés');
                $this->getRequest()->getSession()->set('_saved',1);
                
                // Récupération du nouveau groupe modifié pour affichage
                $newgroup = new Group();
                $newgroup->setCn($cn);
                $newmembers = new ArrayCollection();

                // Recherche des membres dans le LDAP
                $narUsers = $this->getLdap()->getMembersGroup($cn.",ou=private");

                // Affichage des membres  
                for ($i=0; $i<$narUsers["count"]; $i++) {                     
                    $newmembers[$i] = new Member();
                    $newmembers[$i]->setUid($narUsers[$i]["uid"][0]);
                    $newmembers[$i]->setDisplayname($narUsers[$i]["displayname"][0]);
                    $newmembers[$i]->setMail($narUsers[$i]["mail"][0]);
                    $newmembers[$i]->setTel($narUsers[$i]["telephonenumber"][0]);
                    $newmembers[$i]->setMember(TRUE); 
                    $newmembers[$i]->setAdmin(FALSE);
                }
                
                $newgroup ->setMembers($newmembers);

                $editForm = $this->createForm(new PrivateGroupEditType(), $newgroup);
                
                return $this->render('AmuGroupieBundle:Group:privateupdate.html.twig', array('group' => $newgroup, 'nb_membres' => $narUsers["count"], 'form' => $editForm->createView()));
                
            }
            else { 
                $this->getRequest()->getSession()->set('_saved',0);
            }
        }
        
        return array(
            'user'      => $user,
            'cn' => $cn,
            'opt' => $opt,
            'form'   => $editForm->createView(),
        );
    }
    
    /**
     * Voir les appartenances et droits d'un utilisateur.
     *
     * @Route("/see/{uid}", name="see_user")
     * @Template()
     */
    public function seeAction(Request $request, $uid)
    {
        $membersof = array();
        $adminsof = array();
        
        // Recherche des groupes dont l'utilisateur est membre 
        $arData=$this->getLdap()->arDatasFilter("member=uid=".$uid.",ou=people,dc=univ-amu,dc=fr",array("cn", "description", "amugroupfilter"));
        for ($i=0; $i<$arData["count"]; $i++) {
            // on ne récupere que les groupes publics
            if (!strstr($arData[$i]["dn"], "ou=private")) {
                $gr = new Group();
                $gr->setCn($arData[$i]["cn"][0]);
                $gr->setDescription($arData[$i]["description"][0]);
                $gr->setAmugroupfilter($arData[$i]["amugroupfilter"][0]);
                $membersof[] = $gr;
            }
        }
                
        // Récupération des groupes dont l'utilisateur est admin
        $arDataAdmin=$this->getLdap()->arDatasFilter("amuGroupAdmin=uid=".$uid.",ou=people,dc=univ-amu,dc=fr",array("cn", "description", "amugroupfilter"));
        for ($i=0; $i<$arDataAdmin["count"]; $i++) {
            $gr_adm = new Group();
            $gr_adm->setCn($arDataAdmin[$i]["cn"][0]);
            $gr_adm->setDescription($arDataAdmin[$i]["description"][0]);
            $gr_adm->setAmugroupfilter($arDataAdmin[$i]["amugroupfilter"][0]);
            $adminsof[] = $gr_adm;
        }
        
        return array('uid' => $uid,
                    'nb_grp_membres' => $arData["count"], 
                    'grp_membres' => $membersof,
                    'nb_grp_admins' => $arDataAdmin["count"],
                    'grp_admins' => $adminsof);
    }
    
    /**
     * Voir les appartenances et droits d'un utilisateur.
     *
     * @Route("/seeprivate/{uid}", name="see_user_private")
     * @Template()
     */
    public function seeprivateAction(Request $request, $uid)
    {
        $membersof = array();
        $propof = array();
        
        // Recherche des groupes dont l'utilisateur est membre 
        $arData=$this->getLdap()->arDatasFilter("member=uid=".$uid.",ou=people,dc=univ-amu,dc=fr",array("cn", "description", "amugroupfilter"));
        $nb_grp_memb = 0;

        for ($i=0; $i<$arData["count"]; $i++) {
            // on ne récupere que les groupes privés
            if (strstr($arData[$i]["dn"], "ou=private")) {
                $gr = new Group();
                $gr->setCn($arData[$i]["cn"][0]);
                $gr->setDescription($arData[$i]["description"][0]);
                $membersof[] = $gr;
                $nb_grp_memb++;
            }
        }
                
        // Récupération des groupes dont l'utilisateur est propriétaire
        $arDataProp=$this->getLdap()->arDatasFilter("(&(objectClass=groupofNames)(cn=".$uid.":*))",array("cn","description"));

        for ($i=0; $i<$arDataProp["count"]; $i++) {
            $gr_prop = new Group();
            $gr_prop->setCn($arDataProp[$i]["cn"][0]);
            $gr_prop->setDescription($arDataProp[$i]["description"][0]);
            $propof[] = $gr_prop;
        }
        
        return array('uid' => $uid,
                    'nb_grp_membres' => $nb_grp_memb, 
                    'grp_membres' => $membersof,
                    'nb_grp_prop' => $arDataProp["count"],
                    'grp_prop' => $propof);
    }
    
    /**
    * Recherche de personnes
    *
    * @Route("/search/{opt}/{cn}/{liste}",name="user_search")
    * @Template()
    */
    public function searchAction(Request $request, $opt='search', $cn='', $liste='') {
        $usersearch = new User();
        $users = array();
        $u = new User();
        $u->setExacte(true);

        // On récupère le service ldapfonctions
        $ldapfonctions = $this->container->get('groupie.ldapfonctions');
        $ldapfonctions->SetLdap($this->get('amu.ldap'));
        
        // Création du formulaire de recherche
        $form = $this->createForm(new UserSearchType(),
            $u,
            array('action' => $this->generateUrl('user_search', array('opt'=>$opt, 'cn'=>$cn)),
                'method' => 'GET'));
        $form->handleRequest($request);

        if ($form->isValid()) {
            // Récupération des données du formulaire
            $usersearch = $form->getData();


            
            // On teste si on a qqchose dans le champ uid
            if ($usersearch->getUid()=='') {
                // si on a rien, on teste le nom
                // On teste si on fait une recherche exacte ou non
                if ($usersearch->getExacte()) {
                    $arData=$ldapfonctions->recherche("(&(sn=".$usersearch->getSn().")(&(!(edupersonprimaryaffiliation=student))(!(edupersonprimaryaffiliation=alum))(!(edupersonprimaryaffiliation=oldemployee))))", array('uid', 'sn','displayname', 'mail', 'telephonenumber', 'amuComposante', 'supannEntiteAffectation', 'memberof'));
                }
                else {
                    $arData=$ldapfonctions->recherche("(&(sn=".$usersearch->getSn()."*)(&(!(edupersonprimaryaffiliation=student))(!(edupersonprimaryaffiliation=alum))(!(edupersonprimaryaffiliation=oldemployee))))", array('uid', 'sn','displayname', 'mail', 'telephonenumber', 'amuComposante', 'supannEntiteAffectation', 'memberof'));
                }
                
                // on récupère la liste des uilisateurs renvoyés par la recherche
                $nb=0;
                for($i=0;$i<$arData['count'];$i++) {
                    $data = $arData[$i];
                        
                    $user = new User();
                    $user->setUid($data['uid'][0]);
                    $user->setDisplayname($data['displayname'][0]); 
                    $user->setMail($data['mail'][0]);
                    $user->setSn($data['sn'][0]);
                    $user->setTel($data['telephonenumber'][0]);
                    $user->setComp($data['amucomposante'][0]);
                    $user->setAff($data['supannentiteaffectation'][0]);
                    $users[] = $user; 
                    $nb++;    
                }
                
                // Gestion des droits
                $droits = 'Aucun';
                // Droits DOSI seulement en visu
                if (true === $this->get('security.context')->isGranted('ROLE_DOSI')) {
                    $droits = 'Voir';
                }
                if ((true === $this->get('security.context')->isGranted('ROLE_GESTIONNAIRE')) || (true === $this->get('security.context')->isGranted('ROLE_ADMIN')))  {
                    $droits = 'Modifier';
                }
                    
                // Mise en session des résultats de la recherche
                $this->container->get('request')->getSession()->set('users', $users);
                    
                // Si on a un seul résultat de recherche, affichage direct de l'utilisateur concerné en fonction des droits
                if ($nb==1) {
                    if ($opt == 'searchprivate')
                    {
                        return $this->redirect($this->generateUrl('voir_user_private', array('uid' => $user->getUid()))); 
                    }
                    
                    return $this->redirect($this->generateUrl('user_update', array('uid' => $user->getUid())));
                }
                // Sinon, affichage du tableau d'utilisateurs
                return $this->render('AmuGroupieBundle:User:search.html.twig',array('users' => $users, 'opt' => $opt, 'droits' => $droits, 'cn' => $cn, 'liste' => $liste));
            }
            else {
                // Recherche des utilisateurs dans le LDAP
                $arData=$ldapfonctions->recherche("uid=".$usersearch->getUid(), array('uid', 'sn','displayname', 'mail', 'telephonenumber','amucomposante', 'supannentiteaffectation', 'memberof'), "uid");
                
                // Test de la validité de l'uid
                if ($arData[0]['uid'][0] == '') {
                    $this->get('session')->getFlashBag()->add('flash-notice', 'L\'uid n\'est pas valide');
                    $this->getRequest()->getSession()->set('_saved', 0);    
                }
                else {
                    $user = new User();
                    $user->setUid($usersearch->getUid());
                    $user->setDisplayname($arData[0]['displayname'][0]);
                    $user->setMail($arData[0]['mail'][0]);
                    $user->setSn($arData[0]['sn'][0]);
                    $user->setTel($arData[0]['telephonenumber'][0]);
                    $user->setComp($arData[0]['amucomposante'][0]);
                    $user->setAff($arData[0]['supannentiteaffectation'][0]);
                    // Récupération du cn des groupes (memberof)
                    $tab = array_splice($arData[0]['memberof'], 1);
                    $tab_cn = array();
                    foreach($tab as $dn) 
                        $tab_cn[] = preg_replace("/(cn=)(([A-Za-z0-9:_-]{1,}))(,ou=.*)/", "$3", $dn);        
                    $user->setMemberof($tab_cn); 

                    $users[] = $user; 
                    
                    // Gestion des droits
                    $droits = 'Aucun';
                    // Droits DOSI seulement en visu
                    if (true === $this->get('security.context')->isGranted('ROLE_DOSI')) {
                        $droits = 'Voir';
                    }
                    if ((true === $this->get('security.context')->isGranted('ROLE_GESTIONNAIRE')) || (true === $this->get('security.context')->isGranted('ROLE_ADMIN'))) {
                        $droits = 'Modifier';
                    }
                    // Mise en session des résultats de la recherche
                    $this->container->get('request')->getSession()->set('users', $users); 
                    
                    if ($opt == 'addprivate') {
                        return $this->redirect($this->generateUrl('user_add_private', array('uid' => $user->getUid(), 'cn'=>$cn, 'opt'=>'recherche'))); 
                    }
                    if ($opt == 'add')
                    {
                        return $this->redirect($this->generateUrl('user_add', array('uid' => $user->getUid(), 'cn'=>$cn, 'liste' => $liste))); 
                    }
                    if ($opt == 'searchprivate')
                    {
                        return $this->redirect($this->generateUrl('see_user_private', array('uid' => $user->getUid())));
                    }
                        
                    return $this->redirect($this->generateUrl('user_update', array('uid' => $user->getUid())));              
                }
            }       
        }         
        return $this->render('AmuGroupieBundle:User:usersearch.html.twig', array('form' => $form->createView(), 'opt' => $opt, 'cn' => $cn, 'liste' => $liste));
        
    }

    /**
    * Affichage d'une liste d'utilisateurs en session
    *
    * @Route("/display/{opt}/{cn}/{liste}",name="user_display")
    */
    public function displayAction(Request $request, $opt='search', $cn='', $liste='') {
        // Récupération des utilisateurs mis en session
        $users = $this->container->get('request')->getSession()->get('users');

        // Gestion des droits
        $droits = 'Aucun';
        // Droits DOSI seulement en visu
        if (true === $this->get('security.context')->isGranted('ROLE_DOSI')) {
            $droits = 'Voir';
        }
        if ((true === $this->get('security.context')->isGranted('ROLE_GESTIONNAIRE')) || (true === $this->get('security.context')->isGranted('ROLE_ADMIN'))) {
            $droits = 'Modifier';
        }
                        
        return $this->render('AmuGroupieBundle:User:search.html.twig',array('users' => $users, 'opt' => $opt, 'droits' => $droits, 'cn' => $cn, 'liste' => $liste));
    }
   
    /**
    * Formulaire pour l'ajout d'utilisateurs en masse
    *
    * @Route("/multiple/{opt}/{cn}/{liste}",name="user_multiple")
    * @Template("AmuGroupieBundle:User:multiple.html.twig")
    */
    public function multipleAction(Request $request, $opt='search', $cn='', $liste='') {
        // Création du formulaire
        $form = $this->createForm(new UserMultipleType());
        $form->handleRequest($request);
        if ($form->isValid()) {
            // Initialisation des tableaux
            $tabErreurs = array();
            $tabUids = array();
            $users = array();
            $tabMemb = array();
                
            // Récuparation des données du formulaire
            $tab = $form->getData();
            $liste_ident = $tab['multiple'];
            // Récupérer un tableau avec une ligne par uid/mail
            $tabLignes = explode("\n", $liste_ident);
                
            // Log ajout sur le groupe
            openlog("groupie", LOG_PID | LOG_PERROR, LOG_LOCAL0);
            $adm = $request->getSession()->get('login');
                
            // Boucle sur la liste
            foreach($tabLignes as $l) {
                // on élimine les caractères superflus
                $ligne = trim($l);
                
                // test mail ou login
                if (stripos($ligne , "@")>0) {
                    // C'est un mail
                    $r = $this->getLdap()->getUidFromMail($ligne);
                        
                    // Si pb connexion ldap
                    if ($r==false) {
                        // Affichage erreur
                        $this->get('session')->getFlashBag()->add('flash-error', "Erreur LDAP lors de la récupération du mail $ligne");          
                        // Log erreur
                        syslog(LOG_ERR, "LDAP ERREUR : get_uid_from_mail by $adm : $ligne");
                    }
                    else { 
                        // Si le mail n'est pas valide, on le note
                        if ($r['count']==0)
                                $tabErreurs[] = $ligne;
                        else {
                            // Récupération des appartenances de l'utilisateur à ajouter
                            $arGroups = array_splice($r[0]['memberof'], 1);
                            $stop=0;
                            foreach($arGroups as $dn)  {
                                $c = preg_replace("/(cn=)(([A-Za-z0-9:_-]{1,}))(,ou=.*)/", "$3", $dn);
                                if ($c==$cn) {
                                    // l'utilisateur est déjà membre de ce groupe
                                    $stop = 1;
                                    break;
                                }
                            }
                                
                            // Si l'utilisateur n'est pas membre du groupe
                            if ($stop==0) {
                                // On ajoute l'uid à la liste des utilisateurs à ajouter
                                $tabUids[] = $r[0]['uid'][0]; 
                                
                                // Remplissage "user"
                                $user = new User();
                                $user->setUid($r[0]['uid'][0]);
                                $user->setDisplayname($r[0]['displayname'][0]);
                                $user->setSn($r[0]['sn'][0]);
                                $user->setMail($r[0]['mail'][0]);
                                $user->setTel($r[0]['telephonenumber'][0]);
                                $users[] = $user;
                            }
                            else {
                                // L'utilisateur est déjà membre, on le note
                                $tabMemb[] = $r[0]['uid'][0];
                            }
                        }
                    }
                }
                else {
                    // C'est un login
                    $r = $this->getLdap()->TestUid($ligne);
                     
                    // Si pb connexion ldap
                    if ($r==false) {
                        // Affichage erreur
                        $this->get('session')->getFlashBag()->add('flash-error', "Erreur LDAP lors de l'uid $ligne");
                                            
                        // Log erreur
                        syslog(LOG_ERR, "LDAP ERREUR : get_uid_from_mail by $adm : $ligne");
                    }
                    else {
                        // Si l'uid n'est pas valide, on le note
                        if ($r['count']==0) {
                            $tabErreurs[] = $ligne; 
                        }
                        else {
                            // Récupération des appartenances de l'utilisateur à ajouter
                            $arGroups = array_splice($r[0]['memberof'], 1);
                            $stop=0;
                            foreach($arGroups as $dn) {
                                $c = preg_replace("/(cn=)(([A-Za-z0-9:_-]{1,}))(,ou=.*)/", "$3", $dn);
                                if ($c==$cn) {
                                    // l'utilisateur est déjà membre de ce groupe
                                    $stop = 1;
                                    break;
                                }
                            }
                                
                            // Si l'utilisateur n'est pas membre du groupe
                            if ($stop==0) {
                                // On ajoute l'uid à la liste des utilisateurs à ajouter
                                $tabUids[] = $r[0]['uid'][0]; 
                                
                                // Remplissage "user"
                                $user = new User();
                                $user->setUid($r[0]['uid'][0]);
                                $user->setDisplayname($r[0]['displayname'][0]);
                                $user->setSn($r[0]['sn'][0]);
                                $user->setMail($r[0]['mail'][0]);
                                $user->setTel($r[0]['telephonenumber'][0]);
                                $users[] = $user; 
                            }
                            else {
                                // L'utilisateur est déjà membre, on le note
                                $tabMemb[] = $r[0]['uid'][0];
                            }
                        }
                    }
                }
            }
              
            // Ajout de la liste valide au groupe dans le LDAP
            $dn_group = "cn=" . $cn . ", ou=groups, dc=univ-amu, dc=fr";
            $b = $this->getLdap()->addMemberGroup($dn_group, $tabUids);
                
            if ($b) {
                // Log modif
                foreach($tabUids as $u) {
                    syslog(LOG_INFO, "add_member by $adm : group : $cn, user : $u");
                }
            }
            else {
                // Log erreur
                syslog(LOG_ERR, "LDAP ERROR : add_member by $adm : group : $c, multiple user");
            }     
                
            // Affichage de ce qui a été fait dans le message flash
            if (sizeof($users)>0) {
                $this->get('session')->getFlashBag()->add('flash-notice', 'Les utilisateurs suivants ont été ajoutés : ');
                $l="";
                foreach($tabUids as $u)
                    $l = $l.', '.$u;
                $l = substr($l, 1) ;
                $this->get('session')->getFlashBag()->add('flash-notice', $l);
            }
            if (sizeof($tabErreurs)>0) {
                $this->get('session')->getFlashBag()->add('flash-notice', 'Les identifiants/mails suivants ne sont pas valides : ');
                $l="";
                foreach($tabErreurs as $u)
                    $l = $l.', '.$u;
                $l = substr($l, 1) ;
                $this->get('session')->getFlashBag()->add('flash-notice', $l);
            }
            if (sizeof($tabMemb)>0){
                $this->get('session')->getFlashBag()->add('flash-notice', 'Les utilisateurs avec les identifiants suivants sont déjà membres du groupe : ');
                $l="";
                foreach($tabMemb as $u)
                    $l = $l.', '.$u;
                $l = substr($l, 1) ;
                $this->get('session')->getFlashBag()->add('flash-notice', $l);
            }
                
            return $this->redirect($this->generateUrl('group_update', array('cn'=>$cn, 'liste'=>$liste)));
        }
                            
        return $this->render('AmuGroupieBundle:User:multiple.html.twig', array('form' => $form->createView(), 'opt' => $opt, 'cn' => $cn, 'liste' => $liste));
    }
    
}
