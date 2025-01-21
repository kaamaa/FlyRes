<?php
// src/Repository/ToolsAirportRepository.php

namespace App\Repository;

use App\Entity\ToolsAirport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ToolsAirportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ToolsAirport::class);
    }

    public static function GetAirportById ($em, $id)
    {
        if (!empty($id))
        {  
            $country = $em->getRepository('App\Entity\ToolsAirport')->findOneBy(array('id' => $id));
            return $country;
        }
        else return null;
    }

    // Methode zum Abrufen der Koordinaten
    public static function findCoordinatesByAirportName($em, $airport)
    {
        $querystring = "SELECT b FROM App\Entity\ToolsAirport b WHERE b.Airport_ICAO = :airport";
        $query = $em->createQuery($querystring);
        $query->setCacheable(true);
        $query->setParameter('airport', $airport);
        $airport = $query->getResult();
        return $airport;
    }

    public static function GetAllAirportsForListbox ($em, $country_code)
    {
        $airportlist = array();
        $querystring = "SELECT b FROM App\Entity\ToolsAirport b WHERE b.Country = :country_code and b.Type = 'A' ORDER BY b.Airport_ICAO ASC";
        $query = $em->createQuery($querystring);
        $query->setCacheable(true);
        $query->setParameter('country_code', $country_code);
        $airports = $query->getResult();
        foreach ($airports as $airport) 
        {
            $airportlist[$airport->getAirportICAO()] = $airport->getAirportICAO();
        }

        return $airportlist;
    }
}