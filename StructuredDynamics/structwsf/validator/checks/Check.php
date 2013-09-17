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
    abstract public function fix();
    
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
    
    /** Encode a string to put in a JSON value
            
        @param $string The string to escape

        @return returns the escaped string

        @author Frederick Giasson, Structured Dynamics LLC.
    */
    public function jsonEncode($string) { return str_replace(array ('\\', '"', "\n", "\r", "\t"), array ('\\\\', '\\"', " ", " ", "\\t"), $string); }

    /** Encode content to be included in XML files

        @param $string The content string to be encoded
        
        @return returns the encoded string
      
        @author Frederick Giasson, Structured Dynamics LLC.
    */
    public function xmlEncode($string)
    { 
      // Replace all the possible entities by their character. That way, we won't "double encode" 
      // these entities. Otherwise, we can endup with things such as "&amp;amp;" which some
      // XML parsers doesn't seem to like (and throws errors).
      $string = str_replace(array ("&amp;", "&lt;", "&gt;"), array ("&", "<", ">"), $string);
      
      return str_replace(array ("&", "<", ">"), array ("&amp;", "&lt;", "&gt;"), $string); 
    }
    
    
  }
  
?>
