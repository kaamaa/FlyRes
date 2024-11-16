<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use APY\DataGridBundle\Grid\Mapping as GRID;
use Doctrine\ORM\Mapping\Entity;
use App\Entity\FresUser2Functions;
use App\Entity\FresFunction;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Security\Core\User\UserInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Cache;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

/**
 * App\Entity\FresAccounts
 *
 * @ORM\Table(name="FRes_accounts")
 * @ORM\Entity
 * @ORM\Cache(usage="NONSTRICT_READ_WRITE")
 * @GRID\Source(groupBy={"id"})
 * @ORM\Entity(repositoryClass="App\Repository\UserRepository")
*/
class FresAccounts implements UserInterface, PasswordAuthenticatedUserInterface 
{

  /**
   * @var string $clientid
   *
   * @ORM\Column(name="clientid", type="integer", nullable=false)
   * @GRID\Column(visible=false)
   **/
  private $clientid;

  /**
   * @var integer $id
   *
   * @ORM\Column(name="id", type="integer", nullable=false)
   * @ORM\Id
   * @ORM\GeneratedValue(strategy="IDENTITY")
   * @GRID\Column(title="Kunden ID")
   */
  private $id; 
  
  /**
   * @var string $firstname
   *
   * @ORM\Column(name="firstname", type="string", length=30, nullable=true)
   * 
   * @GRID\Column(title="Vorname")
   */
  private $firstname;

  /**
   * @var string $lastname
   *
   * @ORM\Column(name="lastname", type="string", length=30, nullable=true)
   * @GRID\Column(title="Nachname")
   */
  private $lastname;

  /**
   * @var string $username
   *
   * @ORM\Column(name="username", type="string", length=30, nullable=false)
   * @GRID\Column(title="Nutzername")
   */
  private $username;

  /**
   * @var string $password
   *
   * @ORM\Column(name="password", type="string", length=32, nullable=true)
   * @GRID\Column(visible=false)
   */
  private $password;

  /**
   * @var string $email
   *
   * @ORM\Column(name="email", type="string", length=50, nullable=true)
   * @GRID\Column(title="Mailadresse")
   */
  private $email;
  
   /**
    * 
    * @ORM\Cache(usage="NONSTRICT_READ_WRITE")
    * @ORM\ManyToMany(targetEntity="FresFunction", cascade={"all"}, fetch="LAZY")
    * @ORM\JoinTable(name="FRes_user2Functions",
    *      joinColumns={@ORM\JoinColumn(name="userid", referencedColumnName="id")},
    *      inverseJoinColumns={@ORM\JoinColumn(name="functionid", referencedColumnName="id")}
    *      )
    * 
    * @GRID\Column(field="function.function:group_concat", title="Berechtigungen", filterable = false, visible=true)
    * @GRID\Column(field="function.function", title="Berechtigungen", filter="select",  selectFrom="values", defaultOperator="like")
    *
    */
 
  private $function;
   
   
  /**
   * @var boolean $islocked
   *
   * @ORM\Column(name="islocked", type="boolean", nullable=false)
   * @GRID\Column(title="Ist der Nutzer gesperrt?")
   */
  private $islocked;

  /**
   * @var string $phoneNumberHome
   *
   * @ORM\Column(name="phone_number_home", type="string", length=30, nullable=true)
   * @GRID\Column(title="Telefonnummer Privat")
   */
  private $phoneNumberHome;

  /**
   * @var string $phoneNumberOffice
   *
   * @ORM\Column(name="phone_number_office", type="string", length=30, nullable=true)
   * @GRID\Column(title="Telefonnummer Büro")
   */
  private $phoneNumberOffice;

  /**
   * @var string $phoneNumberMobile
   *
   * @ORM\Column(name="phone_number_mobile", type="string", length=30, nullable=true)
   * @GRID\Column(title="Telefonnummer Mobil")
   */
  private $phoneNumberMobile;

  /**
   * @var string $status
   *
   * @ORM\Column(name="status", type="string", length=30, nullable=true)
   * @GRID\Column(visible=false)
   */
  private $status;

  /**
   * @var integer $getbookingmails
   *
   * @ORM\Column(name="getbookingmails", type="integer", nullable=false)
   * @GRID\Column(visible=false)
   */
  private $getbookingmails;

  /**
   * @var integer $getlicencemails
   *
   * @ORM\Column(name="getlicencemails", type="integer", nullable=false)
   * @GRID\Column(visible=false)
   */
  private $getlicencemails;
  
  /**
   * @var boolean $fiparallelbookings
   *
   * @ORM\Column(name="FiParallelBookings", type="boolean", nullable=false)
   * @GRID\Column(visible=false)
   */
  private $fiparallelbookings;
  
  /**
   * @var integer $fiallwaysavailable
   *
   * @ORM\Column(name="FIAllwaysAvailable", type="integer", nullable=false)
   * @GRID\Column(visible=false)
   */
  private $fiallwaysavailable;
  
  /**
   * @var integer $fiallwaysavailable
   *
   * @ORM\Column(name="FIBookableIfOnRequest", type="boolean", nullable=false)
   * @GRID\Column(visible=false)
   */
  private $fibookableifonrequest;
  
  
   public function __construct() 
   {
     // Initialize collection
     $this->function = new ArrayCollection();
   }
   
  /**
   * Set clientid
   *
   * @param integer $clientid
   */
  public function setClientid($clientid) {
    $this->clientid = $clientid;
  }

  /**
   * Get clientid
   *
   * @return integer 
   */
  public function getClientid() {
    return $this->clientid;
  }

  /**
   * Set id
   *
   * @param integer $id 
   */
  public function SetId($id) {
    $this->id = $id;
  }

  /**
   * Get id
   *
   * @return integer 
   */
  public function getId() {
    return $this->id;
  }
  
  /**
   * Set Item
   *
   * @param integer $item
   */
  public function setItem($item) {
    $this->item = $item;
  }

  /**
   * Get item
   *
   * @return integer 
   */
  public function getItem() {
    return $this->item;
  }

  /**
   * Set firstname
   *
   * @param string $firstname
   */
  public function setFirstname($firstname) {
    $this->firstname = $firstname;
  }

  /**
   * Get firstname
   *
   * @return string 
   */
  public function getFirstname() {
    return $this->firstname;
  }

  /**
   * Set lastname
   *
   * @param string $lastname
   */
  public function setLastname($lastname) {
    $this->lastname = $lastname;
  }

  /**
   * Get lastname
   *
   * @return string 
   */
  public function getLastname() {
    return $this->lastname;
  }

  /**
   * Set username
   *
   * @param string $username
   */
  public function setUsername($username) {
    $this->username = $username;
  }

  /**
   * Get username
   *
   * @return string 
   */
  public function getUsername() {
    return $this->username;
  }
  
  /**
  * The public representation of the user (e.g. a username, an email address, etc.)
  *
  * @see UserInterface
  */
  public function getUserIdentifier(): string
  {
      return (string) $this->username;
  }

  /**
   * Set password
   *
   * @param string $password
   */
  public function setPassword($password) {
    $this->password = $password;
  }

  /**
   * Get password
   *
   * @return string 
   */
  public function getPassword(): ?string {
    return $this->password;
  }

  /**
   * Set email
   *
   * @param string $email
   */
  public function setEmail($email) {
    $this->email = $email;
  }

  /**
   * Get email
   *
   * @return string 
   */
  public function getEmail() {
    return $this->email;
  }

  /**
   * Set function
   *
   * @param integer $function
   */
  public function setFunction($function) {
    $this->function = $function;
  }

  /**
   * Get function
   *
   * @return integer 
   */
  public function getFunction() {
    return $this->function;
  }

  /**
   * Set islocked
   *
   * @param boolean $islocked
   */
  public function setIslocked($islocked) {
    $this->islocked = $islocked;
  }

  /**
   * Get islocked
   *
   * @return boolean 
   */
  public function getIslocked() {
    return $this->islocked;
  }

  /**
   * Set phoneNumberHome
   *
   * @param string $phoneNumberHome
   */
  public function setPhoneNumberHome($phoneNumberHome) {
    $this->phoneNumberHome = $phoneNumberHome;
  }

  /**
   * Get phoneNumberHome
   *
   * @return string 
   */
  public function getPhoneNumberHome() {
    return $this->phoneNumberHome;
  }

  /**
   * Set phoneNumberOffice
   *
   * @param string $phoneNumberOffice
   */
  public function setPhoneNumberOffice($phoneNumberOffice) {
    $this->phoneNumberOffice = $phoneNumberOffice;
  }

  /**
   * Get phoneNumberOffice
   *
   * @return string 
   */
  public function getPhoneNumberOffice() {
    return $this->phoneNumberOffice;
  }

  /**
   * Set phoneNumberMobile
   *
   * @param string $phoneNumberMobile
   */
  public function setPhoneNumberMobile($phoneNumberMobile) {
    $this->phoneNumberMobile = $phoneNumberMobile;
  }

  /**
   * Get phoneNumberMobile
   *
   * @return string 
   */
  public function getPhoneNumberMobile() {
    return $this->phoneNumberMobile;
  }

  /**
   * Set status
   *
   * @param string $status
   */
  public function setStatus($status) {
    $this->status = $status;
  }

  /**
   * Get status
   *
   * @return string 
   */
  public function getStatus() {
    return $this->status;
  }
  
  /**
   * Set getbookingmails
   *
   * @param integer $getbookingmails
   */
  public function setGetbookingmails($getbookingmails) {
    $this->getbookingmails = $getbookingmails;
  }

  /**
   * Get getbookingmails
   *
   * @return integer 
   */
  public function getGetbookingmails() {
    return $this->getbookingmails;
  }
  
  /**
   * Set getlicencemails
   *
   * @param integer $getlicencemails
   */
  public function setGetlicencemails($getlicencemails) {
    $this->getlicencemails = $getlicencemails;
  }

  /**
   * Get getlicencemails
   *
   * @return integer 
   */
  public function getGetlicencemails() {
    return $this->getlicencemails;
  }
  
  /**
   * Set fiparallelbookings
   *
   * @param boolean $fiparallelbookings
   */
  public function setFiparallelbookings($fiparallelbookings) {
    $this->fiparallelbookings = $fiparallelbookings;
  }

  /**
   * Get fiparallelbookings
   *
   * @return boolean 
   */
  public function getFiparallelbookings() {
    return $this->fiparallelbookings;
  }
  
  /**
   * Set fiallwaysavailable
   *
   * @param integer $fiallwaysavailable
   */
  public function setFiallwaysavailable($fiallwaysavailable) {
    $this->fiallwaysavailable = $fiallwaysavailable;
  }

  /**
   * Get fiallwaysavailable
   *
   * @return integer 
   */
  public function getFiallwaysavailable() {
    return $this->fiallwaysavailable;
  }
  
  /**
   * Set fibookableifonrequest
   *
   * @param boolean $fibookableifonrequest
   */
  public function setFibookableifonrequest($fibookableifonrequest) {
    $this->fibookableifonrequest = $fibookableifonrequest;
  }

  /**
   * Get fibookableifonrequest
   *
   * @return boolean 
   */
  public function getFibookableifonrequest() {
    return $this->fibookableifonrequest;
  }
  
  
  public function getSalt()
  {
    // später zu füllen
    //return $this->salt;
  }
  
  public function eraseCredentials()
  {
    // später zu füllen
  }
  
  public function getRoles(): array 
  {
    $roles = null;
    $objects = $this->function;
    foreach ($objects as $role) 
    {
      $roles [] = $role->getRole();
    }
    return array_unique($roles);
  }
  
  public function setRoles(array $roles) :self
  {
    // muss noch erweitert werden
    // $this->roles = $roles;
    return $this;
  }
  
  public function __toString()
  {
      return (string) $this->getId();
  }
  
}
