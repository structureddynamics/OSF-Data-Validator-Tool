<?php

  namespace StructuredDynamics\structwsf\validator\checks; 

  abstract class Check
  {
    protected $name;
    protected $description;
    protected $checkOnDatasets;
    protected $checkUsingOntologies;
    protected $network;
    protected $errors = array(); 
    
    function __construct(){}
    
    abstract public function outputXML();
    abstract public function outputJSON();
    abstract public function run();
    
    public function setCheckOnDatasets($datasets)
    {
      $this->checkOnDatasets = $datasets;
    }
    
    public function setCheckUsingOntologies($ontologies)
    {
      $this->checkUsingOntologies = $ontologies;
    }
    
    public function setNetwork($network)
    {
      $this->network = $network;
    }    
    
  }
  
?>
