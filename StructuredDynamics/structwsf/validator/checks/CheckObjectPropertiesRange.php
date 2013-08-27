<?php

  namespace StructuredDynamics\structwsf\validator\checks; 

  use \StructuredDynamics\structwsf\php\api\ws\sparql\SparqlQuery;
  use \StructuredDynamics\structwsf\php\api\ws\ontology\read\OntologyReadQuery;
  use \StructuredDynamics\structwsf\php\api\ws\ontology\read\GetSuperClassesFunction;
  use \StructuredDynamics\structwsf\php\api\ws\crud\read\CrudReadQuery;
  
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

      $sparql = new SparqlQuery($this->network);

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
                        graph ?g {
                          ?s ?p ?o .
                          filter(isIRI(?o))                          
                        } 
                        
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
              
              $sparql = new SparqlQuery($this->network);

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
                                  ?s <http://purl.org/dc/terms/subject> ?value.
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
                    foreach($types as $type)
                    {
                      $ontologyURI = $this->getTypeOntology($type);
                      
                      if($ontologyURI !== FALSE)
                      {
                        $ontologyRead = new OntologyReadQuery($this->network);
                        
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
                          $superClasses = $ontologyRead->getResultset()->getResultset();
                          
                          // If empty, then there is no super-classes
                          if(!empty($superClasses))
                          {                                                     
                            $rangeMatch = FALSE;
                           
                            $superClasses = $superClasses[key($superClasses)];
                           
                            foreach($superClasses as $superClass => $description)
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
                              DebugBreak();
                              cecho('  -> Object property "'.$objectProperty.'" doesn\'t match range "'.$range.'"'."\n", 'LIGHT_RED');
                              
                              $this->errors[] = array(
                                'id' => 'OBJECT-PROPERTIES-RANGE-100',
                                'type' => 'error',
                                'objectProperty' => $objectProperty,
                                'definedRange' => $range,
                                'valueTypes' => implode(', ', $types),
                                'valueSuperTypes' => implode(', ', array_keys($superClasses))
                              );                                 
                            }
                          }
                          else
                          {
                            // Log an error
                            // There is no super-class, so we couldn't confirm that the type is in the range of this property
                          }
                        }
                        else
                        {
                          // Log a warning
                          // Can't get the super classes of the target type
                        }
                      }
                      else
                      {
                        // Log a warning
                        // Can't find ontology where the type $type is defined
                      }
                    }
                  }
                }
              }
            }
            /*

              $results = json_decode($sparql->getResultset(), TRUE);    
              
              if(isset($results['results']['bindings']) && count($results['results']['bindings']) > 0)
              {
                foreach($results['results']['bindings'] as $result)
                {
                  $subject = $result['s']['value'];
                  $numberOfOccurences = $result['numberOfOccurences']['value'];
                  
                  cecho('  -> record: '.$subject."\n", 'LIGHT_RED');
                  cecho('     -> property: '.$property."\n", 'LIGHT_RED');
                  cecho('        -> number of occurences: '.$numberOfOccurences."\n", 'LIGHT_RED');
                  
                  $this->errors[] = array(
                    'id' => 'SCO-MAX-CARDINALITY-100',
                    'invalidRecordURI' => $subject,
                    'invalidPropertyURI' => $property,
                    'numberOfOccurences' => $numberOfOccurences,
                    'maxExpectedNumberOfOccurences' => $maxCadinalities[$property]
                  );                  
                }
              }
            }
            */
          }
          
          /*
          if(count($this->errors) > 0)
          {
            cecho("\n\n  Note: All the errors returned above list records that are being described using too many of a certain type of property. The ontologies does specify that a maximum cardinality should be used for these properties, and what got indexed in the system goes against this instruction of the ontology.\n\n\n", 'LIGHT_RED');
          }
          else
          {
            cecho("\n\n  All properties respects the maximum cardinality specified in the ontologies...\n\n\n", 'LIGHT_GREEN');
          }*/           
        }
        else
        {
          //cecho("No properties have any maximum cardinality defined in any ontologies. Move on to the next check...\n\n\n", 'LIGHT_GREEN');
        }
      }
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
          $xml .= "        <objectProperty>".$error['objectProperty']."</objectProperty>\n";
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
      
      $json .= "    \"validationErrors\": [\n";
      
      $foundWarnings = FALSE;
      foreach($this->errors as $error)
      {
        if($error['type'] == 'warning')
        {
          $json .= "      {\n";
          $json .= "        \"id\": \"".$error['id']."\",\n";
          $json .= "        \"objectProperty\": \"".$error['objectProperty']."\"\n";
          $json .= "      },\n";
          
          $foundWarnings = TRUE;
        }
      }
      
      if($foundWarnings)
      {
        $json = substr($json, 0, strlen($json) - 2)."\n";
      }
      
      
      $foundErrors = FALSE;
      foreach($this->errors as $error)
      {
        if($error['type'] == 'error')
        {
          $json .= "      {\n";
          $json .= "        \"id\": \"".$error['id']."\",\n";
          $json .= "        \"objectProperty\": \"".$error['objectProperty']."\"\n";
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
        $crudRead = new CrudReadQuery($this->network);
        
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
  }
?>
