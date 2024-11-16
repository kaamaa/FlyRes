<?php

namespace App;

class SessionData
{
  // Diese Versionsnummer hochzählen, wenn Änderungen an den Daten des SessionData-Objektes
  // vorgenommen werden
  const version = 2.1;
  
  const day = 1;
  const week = 2;
  const month = 3;
  const fi = 4;
 
  // Typen-Nummer des SessionData Objektes
  protected $m_Version;
  
  protected $m_sUserName;
  
  // Daview
  protected $m_iYear_d;
  protected $m_iMonth_d;
  protected $m_iDay_d;
  
  // Weeksview
  protected $m_iYear_w;
  protected $m_iMonth_w;
  protected $m_iDay_w;
  
  // Monthview
  protected $m_iYear_m;
  protected $m_iMonth_m;
  protected $m_iDay_m;
  
  // FIAvailabilit
  protected $m_iYear_fi;
  protected $m_iMonth_fi;
  protected $m_iDay_fi;
  
  protected $m_sPlaneID;
  protected $m_iBookingID;
  protected $m_iUserID;
  protected $m_iUserLicenceID = 0;
  protected $m_iAircraftTypeID = 0;
  protected $m_iLicenceTypeID = 0;
  protected $m_iNoteID = 0;
  protected $m_iAvailabilityID = 0;
  protected $m_BookingDetailBackRoute = 'weeksview';
  protected $m_FIAvailabiltyCommand = '';

  public function __construct($year, $month, $day)
  {
    // Versionmummer des SessionDataobjektes festlegen
    $this->m_Version = SessionData::version;
    
    $this->SetYear($year, SessionData::day);
    $this->SetYear($year, SessionData::week);
    $this->SetYear($year, SessionData::month);
    $this->SetYear($year, SessionData::fi);
    $this->SetMonth($month, SessionData::day);
    $this->SetMonth($month, SessionData::week);
    $this->SetMonth($month, SessionData::month);
    $this->SetMonth($month, SessionData::fi);
    $this->SetDay($day, SessionData::day);
    $this->SetDay($day, SessionData::week);
    $this->SetDay($day, SessionData::month);
    $this->SetDay($day, SessionData::fi);
  }
  
  public static function IsValid($sd)
  {
    // Diese Funktion prüft, ob das SessionData Objekt das gespeichert wurde die aktuelle
    // Version hat und gibt true zurück, falls dies der Fall ist
    if ($sd != null)
    {
      if (isset($sd->m_Version))
      {
        if ($sd->m_Version == SessionData::version)
        {
          return true;
        }
      }
    }
    return false;
  }
  
  public function GetDateTime($type)
  {
    $day = $this->GetiDay($type); $month = $this->GetiMonth($type); $year = $this->GetiYear($type);
    if (!empty($day) && !empty($month) && !empty($year)) 
    { 
      $dt = new \DateTime();
      $dt->setTimestamp(mktime (0,0,0 ,$this->GetiMonth($type), $this->GetiDay($type), $this->GetiYear($type)));
      return $dt;
    }  
    else
      return new \DateTime('now');
  }

  public function SetYear($year, $type)
  {
    switch ($type) {
    case SessionData::day:
          $this->m_iYear_d = intval($year);
          break;
    case SessionData::week:
          $this->m_iYear_w = intval($year);
          break;
    case SessionData::month:
          $this->m_iYear_m = intval($year);
          break;
    case SessionData::fi:
          $this->m_iYear_fi = intval($year);
          break;    
    }
  }
  
  public function SetMonth($month, $type)
  {
    switch ($type) {
    case SessionData::day:
          $this->m_iMonth_d = intval ($month);
          break;
    case SessionData::week:
          $this->m_iMonth_w = intval ($month);
          break;
    case SessionData::month:
          $this->m_iMonth_m = intval ($month);
          break;
    case SessionData::fi:
          $this->m_iMonth_fi = intval ($month);
          break;    
    }
  }
  
  public function SetDay($day, $type)
  {
    switch ($type) {
    case SessionData::day:
          $this->m_iDay_d = intval ($day);
          break;
    case SessionData::week:
          $this->m_iDay_w = intval ($day);
          break;
    case SessionData::month:
          $this->m_iDay_m = intval ($day);
          break;
    case SessionData::fi:
          $this->m_iDay_fi = intval ($day);
          break;    
    }
  }
  
  public function GetiYear($type)
  {
    switch ($type) {
    case SessionData::day:
          return $this->m_iYear_d;
    case SessionData::week:
          return $this->m_iYear_w;
    case SessionData::month:
          return $this->m_iYear_m;
    case SessionData::fi:
          return $this->m_iYear_fi;  
    }
  }
  
  public function GetsYear($type)
  {
    switch ($type) {
    case SessionData::day:
          return strval($this->m_iYear_d);
    case SessionData::week:
          return strval($this->m_iYear_w);
    case SessionData::month:
          return strval($this->m_iYear_m);
    case SessionData::fi:
          return strval($this->m_iYear_fi);  
    }
  }
  
  public function GetiMonth($type)
  {
    switch ($type) {
    case SessionData::day:
          return $this->m_iMonth_d;
    case SessionData::week:
          return $this->m_iMonth_w;
    case SessionData::month:
          return $this->m_iMonth_m;
    case SessionData::fi:
          return $this->m_iMonth_fi;  
    }
  }
  
  public function GetsMonth($type)
  {
    switch ($type) {
    case SessionData::day:
          return str_pad($this->m_iMonth_d, 2 ,'0', STR_PAD_LEFT);
    case SessionData::week:
          return str_pad($this->m_iMonth_w, 2 ,'0', STR_PAD_LEFT);
    case SessionData::month:
          return str_pad($this->m_iMonth_m, 2 ,'0', STR_PAD_LEFT);
    case SessionData::fi:
          return str_pad($this->m_iMonth_fi, 2 ,'0', STR_PAD_LEFT);  
    }
  }
  public function GetiDay($type)
  {
    switch ($type) {
    case SessionData::day:
          return $this->m_iDay_d;
    case SessionData::week:
          return $this->m_iDay_w;
    case SessionData::month:
          return $this->m_iDay_m;
    case SessionData::fi:
          return $this->m_iDay_fi;  
    }
  }
  
  public function GetsDay($type)
  {
    switch ($type) {
    case SessionData::day:
          return str_pad($this->m_iDay_d, 2 ,'0', STR_PAD_LEFT);
    case SessionData::week:
          return str_pad($this->m_iDay_w, 2 ,'0', STR_PAD_LEFT);
    case SessionData::month:
          return str_pad($this->m_iDay_m, 2 ,'0', STR_PAD_LEFT);
    case SessionData::fi:
          return str_pad($this->m_iDay_fi, 2 ,'0', STR_PAD_LEFT);  
    }
  }
  
  public function GetsFullDate($type)
  {
    return $this->GetsDay($type) . '.' . $this->GetsMonth($type) . '.' . $this->GetsYear($type);
  }
  
  public function SetPlaneID($PlaneID)
  {
    $this->m_sPlaneID = $PlaneID;
  }
  
  public function GetPlaneID()
  {
    return $this->m_sPlaneID;
  }
  public function SetBookingID($BookingID)
  {
    $this->m_iBookingID = $BookingID;
  }
  
  public function GetBookingID()
  {
    return $this->m_iBookingID;
  }
  public function SetUserID($userID)
  {
    $this->m_iUserID = $userID;
  }
  
  public function GetUserID()
  {
    return $this->m_iUserID;
  }
  
  public function SetDate($dt, $type)
  {
    $this->SetDay((int) date('d', $dt->getTimestamp ()), $type);
    $this->SetMonth((int) date('m', $dt->getTimestamp ()), $type);
    $this->SetYear((int) date('Y', $dt->getTimestamp ()), $type);
  }
  
  public function SetUserLicenceID($userLicenceID)
  {
    $this->m_iUserLicenceID = $userLicenceID;
  }
  
  public function GetUserLicenceID()
  {
    return $this->m_iUserLicenceID;
  }
  
  public function SetBookingDetailBackRoute($BackRoute)
  {
    $this->m_BookingDetailBackRoute = $BackRoute;
  }
  
  public function GetBookingDetailBackRoute()
  {
    return $this->m_BookingDetailBackRoute;
  }
  
  public function SetAircraftTypeID($AircraftTypeID)
  {
    $this->m_iAircraftTypeID = $AircraftTypeID;
  }
  
  public function GetAircraftTypeID()
  {
    return $this->m_iAircraftTypeID;
  }
  
  public function SetLicenceTypeID($LicenceTypeID)
  {
    $this->m_iLicenceTypeID = $LicenceTypeID;
  }
  
  public function GetLicenceTypeID()
  {
    return $this->m_iLicenceTypeID;
  }
  
  public function SetNoteID($NoteID)
  {
    $this->m_iNoteID = $NoteID;
  }
  
  public function GetNoteID()
  {
    return $this->m_iNoteID;
  }
  
  public function SetUserName($username)
  {
    $this->m_sUserName = $username;
  }
  
  public function GetUserName()
  {
    return $this->m_sUserName;
  }
  
  public function SetAvailabilityID($AvailabilityID)
  {
    $this->m_iAvailabilityID = $AvailabilityID;
  }
  
  public function GetAvailabilityID()
  {
    return $this->m_iAvailabilityID;
  }
  
  public function SetFIAvailabiltyCommand(string $command)
  {
    $this->m_FIAvailabiltyCommand = $command;
  }
  
  public function GetFIAvailabiltyCommand()
  {
    return $this->m_FIAvailabiltyCommand;
  }
 
}