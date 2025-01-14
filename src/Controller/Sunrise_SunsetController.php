<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use App\Entity\ToolsCountry;
use App\Repository\ToolsCountryRepository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class Sunrise_SunsetController extends AbstractController
{
  public function __construct(ManagerRegistry $registry)
  {
      parent::__construct($registry, ToolsCountry::class);
  }
  
  public function findAllCountries()
  {
      return $this->createQueryBuilder('c')
          ->orderBy('c.name', 'ASC')
          ->getQuery()
          ->getResult();
  }
  
  public function BuildForm($em, $note)
  {  
    $form = $this->createFormBuilder($note)
      ->add('country', EntityType::class, [
      'class' => ToolsCountry::class,
      'query_builder' => function (ToolsCountryRepository $repo) {
          return $repo->createQueryBuilder('c')
              ->orderBy('c.name', 'ASC');
      },
      'choice_label' => 'name',
      'placeholder' => 'Choose a country',
    ])
    ->getForm();
    return $form;
  }
  
  public function ViewAction(Request $request)
  {
    $em = $this->getDoctrine()->getManager();
    $note = new ToolsCountry();
    $form = $this->BuildForm($em, $note);
    $form->handleRequest($request);
    if ($form->isSubmitted() && $form->isValid()) {
      $note = $form->getData();
      $country = $note->getCountry();
      $country_name = $country->getName();
      $country_code = $country->getCode();
      $sunrise_sunset = $this->getSunriseSunset($country_code);
      return $this->render('sunrise_sunset/view.html.twig', [
        'form' => $form->createView(),
        'sunrise_sunset' => $sunrise_sunset,
        'country_name' => $country_name,
      ]);
    }
    return $this->render('sunrise_sunset/view.html.twig', [
      'form' => $form->createView(),
    ]);
  }
}
