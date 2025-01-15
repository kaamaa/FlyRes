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

    public static function GetAllCountriesForListbox ($em)
    {
        $countrylist = array();
        $querystring = "SELECT b FROM App\Entity\ToolsCountry b ORDER BY b.Country_Name ASC";
        $query = $em->createQuery($querystring);
        $query->setCacheable(true);
        $countries = $query->getResult();
        foreach ($countries as $country) 
        {
            $countrylist[$country->getCountryName()] = $country->getId();
        }

        return $countrylist;
    }
}