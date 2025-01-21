<?php
// src/Repository/ToolsCountryRepository.php

namespace App\Repository;

use App\Entity\ToolsCountry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ToolsCountryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ToolsCountry::class);
    }

    public static function GetCountryById ($em, $id)
    {
        if (!empty($id))
        {  
            $country = $em->getRepository('App\Entity\ToolsCountry')->findOneBy(array('id' => $id));
            return $country;
        }
        else return null;
    }
    
    public static function GetCountryCode ($em, $name)
    {
        if (!empty($name))
        {  
            $country = $em->getRepository('App\Entity\ToolsCountry')->findOneBy(array('Country_Name' => $name));
            return $country->getCountryCode();
        }
        else return null;
    }

    public static function GetTimeZone ($em, $countryCode)
    {
        if (!empty($countryCode))
        {  
            $country = $em->getRepository('App\Entity\ToolsCountry')->findOneBy(array('Country_Code' => $countryCode));
            return $country->getTimezone();
        }
        else return null;
    }

    public static function GetAllCountriesForListbox ($em)
    {
        $countrylist = array();
        $querystring = "SELECT b FROM App\Entity\ToolsCountry b ORDER BY b.Country_Name ASC";
        $query = $em->createQuery($querystring);
        $query->setCacheable(true);
        $countries = $query->getResult();
        foreach ($countries as $country) 
        {
            $countrylist[$country->getCountryName()] = $country->getCountryName();
        }

        return $countrylist;
    }
}