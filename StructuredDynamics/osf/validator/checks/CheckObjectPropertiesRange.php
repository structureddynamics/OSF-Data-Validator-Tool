<?php

  namespace StructuredDynamics\osf\validator\checks; 

  use \StructuredDynamics\osf\php\api\ws\sparql\SparqlQuery;
  use \StructuredDynamics\osf\php\api\ws\ontology\read\OntologyReadQuery;
  use \StructuredDynamics\osf\php\api\ws\ontology\read\GetSuperClassesFunction;
  use \StructuredDynamics\osf\php\api\ws\crud\read\CrudReadQuery;
  
  class CheckObjectPropertiesRange extends Check
  {
    private $typeOntologyCache = array();
    
    function __construct()
    { 
      $this->name = 'Object Properties Range Check';
      $this->description = 'Make sure that all the object properties used to describe the records uses the proper range as defined in the ontologies';
    }
    
    public function run()
    { 
      cecho("\n\n");
      
      cecho("Data validation test: ".$this->description."...\n\n", 'LIGHT_BLUE');

      $sparql = new SparqlQuery($this->network, $this->appID, $this->apiKey, $this->user);

      $from = '';
      
      foreach($this->checkOnDatasets as $dataset)
      {
        $from .= 'from named <'.$dataset.'> ';
      }
      
      foreach($this->checkUsingOntologies as $ontology)
      {
        $from .= 'from <'.$ontology.'> ';
      }
      
      // Get the list of all the object properties used within the datasets
      $sparql->mime("application/sparql-results+json")
             ->query('select distinct ?p ?range
                      '.$from.'
                      where
                      {
                        {
                          select distinct ?p
                          where
                          {
                            graph ?g {
                              ?s ?p ?o .
                            } 
                          }
                        }
                        
                        ?p a  <http://www.w3.org/2002/07/owl#ObjectProperty> .
                        
                        optional
                        {                                                          
                          ?p <http://www.w3.org/2000/01/rdf-schema#range> ?range .
                        }
                        
                        filter(str(?p) != "http://purl.org/dc/terms/isPartOf" && str(?p) != "http://www.w3.org/1999/02/22-rdf-syntax-ns#value" && str(?p) != "http://www.w3.org/1999/02/22-rdf-syntax-ns#type")
                      }')
             ->send();

      if($sparql->isSuccessful())
      {
        $results = json_decode($sparql->getResultset(), TRUE);    

        if(isset($results['results']['bindings']) && count($results['results']['bindings']) > 0)
        {
          $objectProperties = array();
          $thereAreEmptyRanges = FALSE;

          foreach($results['results']['bindings'] as $result)
          {
            $objectProperty = $result['p']['value'];
            
            $objectProperties[$objectProperty] = '';
            
            if(isset($result['range']))
            {
              $objectProperties[$objectProperty] = $result['range']['value'];
            }
            else
            {
              $thereAreEmptyRanges = TRUE;
            }
          }

          // Display warnings
          if($thereAreEmptyRanges)
          {
            cecho("The following object properties are used to describe records, but their range is not specified in the ontologies. owl:Thing is assumed as the range, but you may want to define it further and re-run this check:\n", 'YELLOW');
            
            foreach($objectProperties as $objectProperty => $range)
            {
              if(empty($range))
              {
                cecho('  -> object property: '.$objectProperty."\n", 'YELLOW');
                
                $this->errors[] = array(
                  'id' => 'OBJECT-PROPERTIES-RANGE-50',
                  'type' => 'warning',
                  'objectProperty' => $objectProperty,
                );                
              }
            }
          }

          // Now, for each object properties that have a range defined,
          // we:
          // 
          //  (a) List all values used for a given property
          //  (b) For each of these values, make sure they comply with what is defined in the ontology as
          //      the range of the 
          foreach($objectProperties as $objectProperty => $range)
          {
            // If the range is empty, we consider it owl:Thing.
            // If the range is owl:Thing, then we simply skip this check since everything is an owl:Thing
            if(!empty($range) && $range != 'http://www.w3.org/2002/07/owl#Thing')
            {
              $values = array();
              
              $sparql = new SparqlQuery($this->network, $this->appID, $this->apiKey, $this->user);

              $from = '';
              
              foreach($this->checkOnDatasets as $dataset)
              {
                $from .= 'from <'.$dataset.'> ';
                $from .= 'from named <'.$dataset.'> ';
              }
              
              foreach($this->checkUsingOntologies as $ontology)
              {
                $from .= 'from <'.$ontology.'> ';
              }
              
              $sparql->mime("application/sparql-results+json")
                     ->query('select distinct ?value ?value_type
                              '.$from.'
                              where
                              {
                                graph ?g {
                                  ?s <'.$objectProperty.'> ?value.
                                  filter(isIRI(?value))
                                }

                                optional
                                {
                                  ?value a ?value_type
                                }
                              }')
                     ->send();

              if($sparql->isSuccessful())
              {
                // Create the array of object-values/types
                $results = json_decode($sparql->getResultset(), TRUE);    
                
                if(isset($results['results']['bindings']) && count($results['results']['bindings']) > 0)
                {
                  foreach($results['results']['bindings'] as $result)
                  {
                    $value = $result['value']['value'];
                    
                    $type = '';
                    if(isset($result['value_type']))
                    {
                      $type = $result['value_type']['value'];
                    }                        
                    
                    if(!isset($values[$value]))
                    {
                      $values[$value] = array();
                    }
                    
                    if(!empty($type) && !in_array($type, $values[$value]))
                    {
                      $values[$value][] = $type;
                    }
                  }
                }

                // For each value/type(s), we do validate that the range is valid
                foreach($values as $value => $types)
                {
                  // First, check if we have a type defined for the value. If not, then we infer it is owl:Thing
                  if(empty($types))
                  {
                    $types = array('http://www.w3.org/2002/07/owl#Thing');
                  }
                  
                  // Then, check if the $range and the $types directly match
                  if(in_array($range, $types))
                  {
                    continue;
                  }
                  else
                  {
                    // If they are not, then we check in the ontology to see if the range is not
                    // one of the super class of one of the type(s)
                    $superClasses = array();
                    
                    foreach($types as $type)
                    {
                      $ontologyURI = $this->getTypeOntology($type);
                      
                      if($ontologyURI !== FALSE)
                      {
                        $ontologyRead = new OntologyReadQuery($this->network, $this->appID, $this->apiKey, $this->user);
                        
                        $getSuperClassesFunc = new GetSuperClassesFunction();
                        
                        $getSuperClassesFunc->allSuperClasses()
                                            ->getClassesUris()
                                            ->uri($type);
                                            
                        $ontologyRead->enableReasoner()
                                     ->ontology($ontologyURI)
                                     ->getSuperClasses($getSuperClassesFunc)
                                     ->mime('resultset')
                                     ->send();
                        
                        if($ontologyRead->isSuccessful())
                        {
                          $scs = $ontologyRead->getResultset()->getResultset();
                          
                          // If empty, then there is no super-classes
                          if(!empty($scs))
                          {                                                     
                            $scs = $scs[key($scs)];
                           
                            foreach($scs as $superClass => $description)
                            {
                              if(!in_array($superClass, $superClasses))
                              {
                                $superClasses[] = $superClass;
                              }
                            }
                          }
                        }
                        else
                        {
                          cecho("We couldn't get the list of super-classes of a target type from the OSF Web Services instance\n", 'YELLOW');
                          
                          // Log a warning
                          // Can't get the super classes of the target type
                          $this->errors[] = array(
                            'id' => 'OBJECT-PROPERTIES-RANGE-51',
                            'type' => 'warning',
                            'objectProperty' => $objectProperty,
                          );                              
                        }
                      }
                      else
                      {
                        cecho("We couldn't find the ontology where the $type is defined on the OSF Web Services instance\n", 'YELLOW');                        
                        
                        // Log a warning
                        // Can't find ontology where the type $type is defined
                        $this->errors[] = array(
                          'id' => 'OBJECT-PROPERTIES-RANGE-52',
                          'type' => 'warning',
                          'objectProperty' => $objectProperty,
                        );                              
                      }
                    }
                    
                    $rangeMatch = FALSE;
      
                    foreach($superClasses as $superClass)
                    {
                      if($superClass == $range)
                      {
                        $rangeMatch = TRUE;
                        break;
                      }
                    }                            
                    
                    if(!$rangeMatch)
                    {
                      // Log an error
                      // Couldn't match one of the super classe with the specified range
                      cecho('  -> Object property "'.$objectProperty.'" doesn\'t match range "'.$range.'" for value "'.$value.'"'."\n", 'LIGHT_RED');
                      
                      $this->errors[] = array(
                        'id' => 'OBJECT-PROPERTIES-RANGE-100',
                        'type' => 'error',
                        'objectProperty' => $objectProperty,
                        'definedRange' => $range,
                        'value' => $value,
                        'valueTypes' => $types,
                        'valueSuperTypes' => $superClasses,
                        'affectedRecords' => $this->getAffectedRecords($objectProperty, $value)
                      );        
                    }
                  }
                }
              }
              else
              {
                cecho("We couldn't get the range of the $objectProperty object property from the OSF Web Services instance\n", 'YELLOW');
                
                $this->errors[] = array(
                  'id' => 'OBJECT-PROPERTIES-RANGE-54',
                  'type' => 'warning',
                );                   
              }
            }           
          }
        }
      }
      else
      {
        cecho("We couldn't get the list of object properties from the OSF Web Services instance\n", 'YELLOW');
        
        $this->errors[] = array(
          'id' => 'OBJECT-PROPERTIES-RANGE-53',
          'type' => 'warning',
        );           
      }
    }
    
    public function fix()
    {
    }    
    
    public function outputXML()
    {
      if(count($this->errors) <= 0)
      {
        return('');
      }
      
      $xml = "  <check>\n";
      
      $xml .= "    <name>".$this->name."</name>\n";      
      $xml .= "    <description>".$this->description."</description>\n";      
      $xml .= "    <onDatasets>\n";
      
      foreach($this->checkOnDatasets as $dataset)
      {
        $xml .= "      <dataset>".$dataset."</dataset>\n";
      }
      
      $xml .= "    </onDatasets>\n";

      $xml .= "    <usingOntologies>\n";
      
      foreach($this->checkUsingOntologies as $ontology)
      {
        $xml .= "      <ontology>".$ontology."</ontology>\n";
      }
      
      $xml .= "    </usingOntologies>\n";

      $xml .= "    <validationWarnings>\n";
      
      foreach($this->errors as $error)
      {
        if($error['type'] == 'warning')
        {
          $xml .= "      <warning>\n";
          $xml .= "        <id>".$error['id']."</id>\n";
          
          if(!empty($error['objectProperty']))
          {
            $xml .= "        <objectProperty>".$error['objectProperty']."</objectProperty>\n";
          }
          
          $xml .= "      </warning>\n";
        }
      }
      
      $xml .= "    </validationWarnings>\n";
      
      $xml .= "    <validationErrors>\n";
      
      foreach($this->errors as $error)
      {
        if($error['type'] == 'error')
        {
          $xml .= "      <error>\n";
          $xml .= "        <id>".$error['id']."</id>\n";
          $xml .= "        <objectProperty>".$error['objectProperty']."</objectProperty>\n";
          $xml .= "        <definedRange>".$error['definedRange']."</definedRange>\n";
          $xml .= "        <value>".$error['value']."</value>\n";
          
          if(!empty($error['valueTypes']))
          {
            $xml .= "        <valueTypes>\n";
            $xml .= "          <valueType>".implode("</valueType>\n          <valueType>", $error['valueTypes'])."</valueType>\n";            
            $xml .= "        </valueTypes>\n";
          }
          
          if(!empty($error['valueSuperTypes']))
          {
            $xml .= "        <valueSuperTypes>\n";
            $xml .= "          <valueSuperType>".implode("</valueSuperType>\n          <valueSuperType>", $error['valueSuperTypes'])."</valueSuperType>\n";            
            $xml .= "        </valueSuperTypes>\n";
          }
          
          if(!empty($error['affectedRecords']))
          {
            $xml .= "        <affectedRecords>\n";
            $xml .= "          <affectedRecord>".implode("</affectedRecord>\n          <affectedRecord>", $error['affectedRecords'])."</affectedRecord>\n";            
            $xml .= "        </affectedRecords>\n";
          }
          
          $xml .= "      </error>\n";
        }
      }
      
      $xml .= "    </validationErrors>\n";
      
      $xml .= "  </check>\n";
      
      return($xml);
    }    
    
    public function outputJSON()
    {
      if(count($this->errors) <= 0)
      {
        return('');
      }
      
      $json = "  {\n";
      
      $json .= "    \"name\": \"".$this->name."\",\n";      
      $json .= "    \"description\": \"".$this->description."\",\n";      
      $json .= "    \"onDatasets\": [\n";
      
      foreach($this->checkOnDatasets as $dataset)
      {
        $json .= "      \"".$dataset."\",\n";
      }
      
      $json = substr($json, 0, strlen($json) - 2)."\n";
      
      $json .= "    ],\n";

      $json .= "    \"usingOntologies\": [\n";
      
      foreach($this->checkUsingOntologies as $ontology)
      {
        $json .= "      \"".$ontology."\",\n";
      }

      $json = substr($json, 0, strlen($json) - 2)."\n";
      
      $json .= "    ],\n";
      
      $json .= "    \"validationWarnings\": [\n";
      
      $foundWarnings = FALSE;
      foreach($this->errors as $error)
      {
        if($error['type'] == 'warning')
        {
          $json .= "      {\n";
          $json .= "        \"id\": \"".$error['id']."\",\n";
          
          if(!empty($error['objectProperty']))
          {
            $json .= "        \"objectProperty\": \"".$error['objectProperty']."\",\n";  
          }
          
          $json = substr($json, 0, strlen($json) - 2)."\n";
          
          $json .= "      },\n";
          
          $foundWarnings = TRUE;
        }
      }
      
      if($foundWarnings)
      {
        $json = substr($json, 0, strlen($json) - 2)."\n";
      }     
      
      $json .= "    ],\n";      
      
      $json .= "    \"validationErrors\": [\n";
      
      $foundErrors = FALSE;
      foreach($this->errors as $error)
      {
        if($error['type'] == 'error')
        {
          $json .= "      {\n";
          $json .= "        \"id\": \"".$error['id']."\",\n";
          $json .= "        \"objectProperty\": \"".$error['objectProperty']."\",\n";
          $json .= "        \"definedRange\": \"".$error['definedRange']."\",\n";
          $json .= "        \"value\": \"".$error['value']."\",\n";
          
          if(!empty($error['valueTypes']))
          {
            $json .= "        \"valueTypes\": [\n";
            $json .= "          \"".implode("\", \n          \"", $error['valueTypes'])."\"\n";
            $json .= "        ],\n";
          }
          
          if(!empty($error['valueSuperTypes']))
          {
            $json .= "        \"valueSuperTypes\": [\n";
            $json .= "          \"".implode("\", \n          \"", $error['valueSuperTypes'])."\"\n";
            $json .= "        ],\n";
          }
                              
          if(!empty($error['affectedRecords']))
          {
            $json .= "        \"affectedRecords\": [\n";
            $json .= "          \"".implode("\", \n          \"", $error['affectedRecords'])."\"\n";
            $json .= "        ],\n";
          }
          
          $json = substr($json, 0, strlen($json) - 2)."\n";
          
          $json .= "      },\n";
          
          $foundErrors = TRUE;
        }
      }
      
      if($foundErrors)
      {
        $json = substr($json, 0, strlen($json) - 2)."\n";
      }      
      
      $json .= "    ]\n";
      
      $json .= "  }\n";
      
      return($json);      
    }       
    
    private function getTypeOntology($type) 
    {
      if(isset($this->typeOntologyCache[$type]))      
      {
        return($type);
      }
      else
      {
        $crudRead = new CrudReadQuery($this->network, $this->appID, $this->apiKey, $this->user);
        
        $crudRead->uri($type)
                 ->mime('resultset')
                 ->send();
                 
        if($crudRead->isSuccessful())
        {
          $resultset = $crudRead->getResultset()->getResultset();
    
          return(key($resultset));
        }
        else
        {
          return(FALSE);
        }
      }
    }
    
    private function getAffectedRecords($objectProperty, $value)
    {
      $affectedRecords = array();
                              
      $sparqlAffectedRecords = new SparqlQuery($this->network, $this->appID, $this->apiKey, $this->user);

      $from = '';
      
      foreach($this->checkOnDatasets as $dataset)
      {
        $from .= 'from <'.$dataset.'> ';
      }
      
      $sparqlAffectedRecords->mime("application/sparql-results+json")
             ->query('select distinct ?s
                      '.$from.'
                      where
                      {
                        ?s <'.$objectProperty.'> <'.$value.'>.
                      }')
             ->send();

      if($sparqlAffectedRecords->isSuccessful())
      { 
        $results = json_decode($sparqlAffectedRecords->getResultset(), TRUE);    
        
        if(isset($results['results']['bindings']) && count($results['results']['bindings']) > 0)
        {
          foreach($results['results']['bindings'] as $result)
          {
            $s = $result['s']['value'];
            
            $affectedRecords[] = $s;
          }
        }                                
      }      
      else
      {
        cecho("We couldn't get the list of affected records from the OSF Web Services instance\n", 'YELLOW');
        
        $this->errors[] = array(
          'id' => 'OBJECT-PROPERTIES-RANGE-55',
          'type' => 'warning',
        );          
      }         
      
      return($affectedRecords);
    }
  }
?>
