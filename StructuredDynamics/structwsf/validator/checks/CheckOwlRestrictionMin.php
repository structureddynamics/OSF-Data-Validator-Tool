<?php

  namespace StructuredDynamics\structwsf\validator\checks; 

  use \StructuredDynamics\structwsf\php\api\ws\sparql\SparqlQuery;
  use \StructuredDynamics\structwsf\php\api\ws\ontology\read\OntologyReadQuery;
  use \StructuredDynamics\structwsf\php\api\ws\ontology\read\GetSubClassesFunction;
  use \StructuredDynamics\structwsf\php\api\ws\crud\read\CrudReadQuery;
  
  /*
  
    at least the specified number of distinct values must be associated with members of the restricted class
    
  */
  
  class CheckOwlRestrictionMin extends Check
  {
    private $classOntologyCache = array();
    
    function __construct()
    {                                     
      $this->name = 'OWL Minimum Cardinality Restriction Check';
      $this->description = 'Make sure that all OWL Minimum Cardinality Restriction defined in the ontologies is respected in the datasets';
    }
    
    public function run()
    {
      cecho("\n\n");
      
      cecho("Data validation test: ".$this->description."...\n\n", 'LIGHT_BLUE');

      // Check for Min Cardinality restriction on Datatype Properties    
      $sparql = new SparqlQuery($this->network);

      $from = '';
      
      foreach($this->checkOnDatasets as $dataset)
      {
        $from .= 'from <'.$dataset.'> ';
      }
      
      foreach($this->checkUsingOntologies as $ontology)
      {
        $from .= 'from named <'.$ontology.'> ';
      }

      $sparql->mime("application/sparql-results+json")
             ->query('select distinct ?restriction ?class ?cardinality ?onProperty ?dataRange
                      '.$from.'
                      where
                      {
                        ?s a ?class.

                        graph ?g {
                            ?class <http://www.w3.org/2000/01/rdf-schema#subClassOf> ?restriction .
                            ?restriction a <http://www.w3.org/2002/07/owl#Restriction> ;
                                         <http://www.w3.org/2002/07/owl#onProperty> ?onProperty ;
                                         <http://www.w3.org/2002/07/owl#minQualifiedCardinality> ?cardinality .

                            optional
                            {
                              ?restriction <http://www.w3.org/2002/07/owl#onDataRange> ?dataRange .
                            }     
                        }
                      }')
             ->send();
             
      if($sparql->isSuccessful())
      {
        $results = json_decode($sparql->getResultset(), TRUE);    
        
        if(isset($results['results']['bindings']) && count($results['results']['bindings']) > 0)
        {
          cecho("Here is the list of all the OWL min cardinality restrictions defined for the classes currently used in the target datasets applied on datatype properties:\n\n", 'LIGHT_BLUE');
          
          $minCardinalities = array();
          
          foreach($results['results']['bindings'] as $result)
          {
            $class = $result['class']['value'];
            $minCardinality = $result['cardinality']['value'];
            $onProperty = $result['onProperty']['value'];
            
            $dataRange = '';
            if(isset($result['dataRange']))
            {
              $dataRange = $result['dataRange']['value'];
            }
            
            cecho('  -> Record of type "'.$class.'" have a minimum cardinality of "'.$minCardinality.'" when using datatype property "'.$onProperty.'" with value type "'.$dataRange.'"'."\n", 'LIGHT_BLUE');
            
            if(!isset($minCardinalities[$class]))
            {
              $minCardinalities[$class] = array();
            }
            
            $minCardinalities[$class][] = array(
              'minCardinality' => $minCardinality,
              'onProperty' => $onProperty,
              'dataRange' => $dataRange
            );
          }

          cecho("\n\n");

          foreach($minCardinalities as $class => $minCards)
          {
            foreach($minCards as $minCardinality)
            {
              $sparql = new SparqlQuery($this->network);

              $from = '';
              
              foreach($this->checkOnDatasets as $dataset)
              {
                $from .= 'from <'.$dataset.'> ';
              }
              
              foreach($this->checkUsingOntologies as $ontology)
              {
                $from .= 'from <'.$ontology.'> ';
              }
              
              $datatypeFilter = '';
              
              // Here, if rdfs:Literal is used, we have to filter for xsd:string also since it is the default
              // used by Virtuoso...
              if(!empty($minCardinality['dataRange']))
              {
                if($minCardinality['dataRange'] == 'http://www.w3.org/2000/01/rdf-schema#Literal')
                {
                  $datatypeFilter = 'filter((str(datatype(?value)) = \'http://www.w3.org/2000/01/rdf-schema#Literal\' || str(datatype(?value)) = \'http://www.w3.org/2001/XMLSchema#string\') && ?onProperty in (<'.$minCardinality['onProperty'].'>))';
                }
                else
                {
                  $datatypeFilter = 'filter((str(datatype(?value)) = \''.$minCardinality['dataRange'].'\') && ?onProperty in (<'.$minCardinality['onProperty'].'>))';
                }
              }
              
              $sparql->mime("application/sparql-results+json")
                     ->query('select ?s ?onProperty count(?onProperty) as ?nb_values
                              '.$from.'
                              where
                              {
                                ?s a <'.$class.'> ;
                                   ?onProperty ?value .

                                '.$datatypeFilter.'
                              }
                              group by ?s ?onProperty
                              having(count(?onProperty) < '.$minCardinality['minCardinality'].')                            
                             ')
                     ->send();

              if($sparql->isSuccessful())
              {
                $results = json_decode($sparql->getResultset(), TRUE);    
                
                if(isset($results['results']['bindings']) && count($results['results']['bindings']) > 0)
                {
                  foreach($results['results']['bindings'] as $result)
                  {
                    $subject = $result['s']['value'];
                    $numberOfOccurrences = $result['nb_values']['value'];
                    
                    cecho('  -> record: '.$subject."\n", 'LIGHT_RED');
                    cecho('     -> property: '.$minCardinality['onProperty']."\n", 'LIGHT_RED');
                    cecho('        -> number of occurrences: '.$numberOfOccurrences."\n", 'LIGHT_RED');
                    
                    $this->errors[] = array(
                      'id' => 'OWL-RESTRICTION-MIN-100',
                      'type' => 'error',
                      'invalidRecordURI' => $subject,
                      'invalidPropertyURI' => $minCardinality['onProperty'],
                      'numberOfOccurrences' => $numberOfOccurrences,
                      'minimumExpectedNumberOfOccurrences' => $minCardinality['minCardinality'],
                      'dataRange' => $minCardinality['dataRange']
                    );                  
                  }
                }
              }
              else
              {
                cecho("We couldn't get the number of properties used per record from the structWSF instance\n", 'YELLOW');
                
                // Error: cam't get the number of properties used per record
                $this->errors[] = array(
                  'id' => 'OWL-RESTRICTION-MIN-51',
                  'type' => 'warning',
                );                     
              }

              // Check the edgecase where the entities are not using this property (so, the previous sparql query won't bind)
              $sparql->mime("application/sparql-results+json")
                     ->query('select ?s
                              '.$from.'
                              where
                              {
                                ?s a <'.$class.'> .
                                
                                filter not exists {
                                  ?s <'.$minCardinality['onProperty'].'> ?value .
                                }
                              }
                             ')
                     ->send();

              if($sparql->isSuccessful())
              {
                $results = json_decode($sparql->getResultset(), TRUE);    
                
                if(isset($results['results']['bindings']) && count($results['results']['bindings']) > 0)
                {
                  foreach($results['results']['bindings'] as $result)
                  {
                    $subject = $result['s']['value'];
                    
                    cecho('  -> record: '.$subject."\n", 'LIGHT_RED');
                    cecho('     -> property: '.$minCardinality['onProperty']."\n", 'LIGHT_RED');
                    cecho('        -> number of occurrences: 0'."\n", 'LIGHT_RED');
                    
                    $this->errors[] = array(
                      'id' => 'OWL-RESTRICTION-MIN-102',
                      'type' => 'error',
                      'invalidRecordURI' => $subject,
                      'invalidPropertyURI' => $minCardinality['onProperty'],
                      'numberOfOccurrences' => '0',
                      'minimumExpectedNumberOfOccurrences' => $minCardinality['minCardinality'],
                      'dataRange' => $minCardinality['dataRange']
                    );                  
                  }
                }                
              } 
              else
              {
                cecho("We couldn't get the list of minimum cardinality restriction from the structWSF instance\n", 'YELLOW');

                // Error: can't get the list of retrictions
                $this->errors[] = array(
                  'id' => 'OWL-RESTRICTION-MIN-52',
                  'type' => 'warning',
                ); 
              }             
            }
          }
          
          if(count($this->errors) <= 0)
          {
            cecho("\n\n  All records respects the minimum restrictions cardinality specified in the ontologies...\n\n\n", 'LIGHT_GREEN');
          }           
        }
        else
        {
          cecho("No classes have any minimum restriction cardinality defined in any ontologies. Move on to the next check...\n\n\n", 'LIGHT_GREEN');
        }
      }
      else
      {
         cecho("We couldn't get the list of minimum cardinality restriction from the structWSF instance\n", 'YELLOW');
         
        // Error: can't get the list of retrictions
        $this->errors[] = array(
          'id' => 'OWL-RESTRICTION-MIN-50',
          'type' => 'warning',
        );                     
      }
      
      // Check for Min Cardinality restriction on Object Properties      
      $sparql = new SparqlQuery($this->network);

      $from = '';
      
      foreach($this->checkOnDatasets as $dataset)
      {
        $from .= 'from <'.$dataset.'> ';
      }
      
      foreach($this->checkUsingOntologies as $ontology)
      {
        $from .= 'from named <'.$ontology.'> ';
      }

      $sparql->mime("application/sparql-results+json")
             ->query('select distinct ?restriction ?class ?cardinality ?onProperty ?classExpression
                      '.$from.'
                      where
                      {
                        ?s a ?class.

                        graph ?g {
                            ?class <http://www.w3.org/2000/01/rdf-schema#subClassOf> ?restriction .
                            ?restriction a <http://www.w3.org/2002/07/owl#Restriction> ;
                                         <http://www.w3.org/2002/07/owl#onProperty> ?onProperty ;
                                         <http://www.w3.org/2002/07/owl#minCardinality> ?cardinality .
                                         
                          optional
                          {
                            ?restriction <http://www.w3.org/2002/07/owl#onClass> ?classExpression .
                          }                                            
                        }
                      }')
             ->send();
             
      if($sparql->isSuccessful())
      {
        $results = json_decode($sparql->getResultset(), TRUE);    
        
        if(isset($results['results']['bindings']) && count($results['results']['bindings']) > 0)
        {
          cecho("Here is the list of all the OWL min cardinality restrictions defined for the classes currently used in the target datasets applied on object properties:\n\n", 'LIGHT_BLUE');
          
          $minCardinalities = array();
          
          foreach($results['results']['bindings'] as $result)
          {
            $class = $result['class']['value'];
            $minCardinality = $result['cardinality']['value'];
            $onProperty = $result['onProperty']['value'];
            
            $classExpression = '';
            if(isset($result['classExpression']))
            {
              $classExpression = $result['classExpression']['value'];
            }            
            
            cecho('  -> Record of type "'.$class.'" have a minimum cardinality of "'.$minCardinality.'" when using object property "'.$onProperty.'"'."\n", 'LIGHT_BLUE');
            
            if(!isset($minCardinalities[$class]))
            {
              $minCardinalities[$class] = array();
            }
            
            $minCardinalities[$class][] = array(
              'minCardinality' => $minCardinality,
              'onProperty' => $onProperty,
              'classExpression' => $classExpression
            );
          }

          cecho("\n\n");

          foreach($minCardinalities as $class => $minCards)
          {
            foreach($minCards as $minCardinality)
            {
              $sparql = new SparqlQuery($this->network);

              $from = '';
              
              foreach($this->checkOnDatasets as $dataset)
              {
                $from .= 'from <'.$dataset.'> ';
              }
              
              foreach($this->checkUsingOntologies as $ontology)
              {
                $from .= 'from <'.$ontology.'> ';
              }
              
              $classExpressionFilter = '';
              if(!empty($minCardinality['classExpression']) && $minCardinality['classExpression'] != 'http://www.w3.org/2002/07/owl#Thing')
              {
                $subClasses = array($minCardinality['classExpression']);
                
                // Get all the classes that belong to the class expression defined in this restriction
                $getSubClassesFunction = new GetSubClassesFunction();
                
                $getSubClassesFunction->getClassesUris()
                                      ->allSubClasses()
                                      ->uri($minCardinality['classExpression']);
                                        
                $ontologyRead = new OntologyReadQuery($this->network);
                
                $ontologyRead->ontology($this->getClassOntology($minCardinality['classExpression']))
                             ->getSubClasses($getSubClassesFunction)
                             ->enableReasoner()
                             ->mime('resultset')
                             ->send();
                             
                if($ontologyRead->isSuccessful())
                {
                  $resultset = $ontologyRead->getResultset()->getResultset();
                  
                  $subClasses = array_merge($subClasses, array_keys($resultset['unspecified']));
                  
                  if(!empty($subClasses))
                  {
                    $classExpressionFilter .= " filter(?value in (<".implode(">, <", $subClasses).">)) \n";
                  }
                }
                else
                {
                  cecho("We couldn't get sub-classes of class expression from the structWSF instance\n", 'YELLOW');
                  
                  $this->errors[] = array(
                    'id' => 'OWL-RESTRICTION-MIN-55',
                    'type' => 'warning',
                  );           
                }
              }

              $sparql->mime("application/sparql-results+json")
                     ->query('select ?s ?onProperty count(?onProperty) as ?nb_values
                              '.$from.'
                              where
                              {
                                ?s a <'.$class.'> ;
                                   ?onProperty ?value .

                                '.$classExpressionFilter.'
                                   
                                filter(?onProperty in (<'.$minCardinality['onProperty'].'>)) .
                              }
                              group by ?s ?onProperty
                              having(count(?onProperty) < '.$minCardinality['minCardinality'].')                            
                             ')
                     ->send();
                     
              if($sparql->isSuccessful())
              {
                $results = json_decode($sparql->getResultset(), TRUE);    
                
                if(isset($results['results']['bindings']) && count($results['results']['bindings']) > 0)
                {
                  foreach($results['results']['bindings'] as $result)
                  {
                    $subject = $result['s']['value'];
                    $numberOfOccurrences = $result['nb_values']['value'];
                    
                    cecho('  -> record: '.$subject."\n", 'LIGHT_RED');
                    cecho('     -> property: '.$minCardinality['onProperty']."\n", 'LIGHT_RED');
                    cecho('        -> number of occurrences: '.$numberOfOccurrences."\n", 'LIGHT_RED');
                    
                    $this->errors[] = array(
                      'id' => 'OWL-RESTRICTION-MIN-101',
                      'type' => 'error',
                      'invalidRecordURI' => $subject,
                      'invalidPropertyURI' => $minCardinality['onProperty'],
                      'numberOfOccurrences' => $numberOfOccurrences,
                      'minimumExpectedNumberOfOccurrences' => $minCardinality['minCardinality'],
                      'classExpression' => $minCardinality['classExpression']
                    );                  
                  }
                }
              }
              else
              {
                cecho("We couldn't get the number of properties used per record from the structWSF instance\n", 'YELLOW');
                
                $this->errors[] = array(
                  'id' => 'OWL-RESTRICTION-MIN-56',
                  'type' => 'warning',
                );                    
              }
              
              // Check the edgecase where the entities are not using this property (so, the previous sparql query won't bind)
              $sparql->mime("application/sparql-results+json")
                     ->query('select ?s
                              '.$from.'
                              where
                              {
                                ?s a <'.$class.'> .
                                
                                filter not exists {
                                  ?s <'.$minCardinality['onProperty'].'> ?value .
                                }
                              }
                             ')
                     ->send();

              if($sparql->isSuccessful())
              {
                $results = json_decode($sparql->getResultset(), TRUE);    
                
                if(isset($results['results']['bindings']) && count($results['results']['bindings']) > 0)
                {
                  foreach($results['results']['bindings'] as $result)
                  {
                    $subject = $result['s']['value'];
                    
                    cecho('  -> record: '.$subject."\n", 'LIGHT_RED');
                    cecho('     -> property: '.$minCardinality['onProperty']."\n", 'LIGHT_RED');
                    cecho('        -> number of occurrences: 0'."\n", 'LIGHT_RED');
                    
                    $this->errors[] = array(
                      'id' => 'OWL-RESTRICTION-MIN-103',
                      'type' => 'error',
                      'invalidRecordURI' => $subject,
                      'invalidPropertyURI' => $minCardinality['onProperty'],
                      'numberOfOccurrences' => '0',
                      'minimumExpectedNumberOfOccurrences' => $minCardinality['minCardinality'],
                      'classExpression' => $minCardinality['classExpression']
                    );                  
                  }
                }                
              } 
              else
              {
                cecho("We couldn't get the list of minimum cardinality restriction from the structWSF instance\n", 'YELLOW');
                
                // Error: can't get the list of retrictions
                $this->errors[] = array(
                  'id' => 'OWL-RESTRICTION-MIN-53',
                  'type' => 'warning',
                ); 
              }              
            }
            
            if(count($this->errors) <= 0)
            {
              cecho("\n\n  All records respects the minimum restrictions cardinality specified in the ontologies...\n\n\n", 'LIGHT_GREEN');
            }           
          }
        }
        else
        {
          cecho("No classes have any minimum restriction cardinality defined in any ontologies. Move on to the next check...\n\n\n", 'LIGHT_GREEN');
        }
      }  
      else
      {
        cecho("We couldn't get the list of minimum cardinality restrictions from the structWSF instance\n", 'YELLOW');
        
        $this->errors[] = array(
          'id' => 'OWL-RESTRICTION-MIN-54',
          'type' => 'warning',
        );           
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
          $xml .= "        <invalidRecordURI>".$error['invalidRecordURI']."</invalidRecordURI>\n";
          $xml .= "        <invalidPropertyURI>".$error['invalidPropertyURI']."</invalidPropertyURI>\n";
          $xml .= "        <numberOfOccurrences>".$error['numberOfOccurrences']."</numberOfOccurrences>\n";
          
          if(!empty($error['minimumExpectedNumberOfOccurrences']))
          {
            $xml .= "        <minimumExpectedNumberOfOccurrences>".$error['minimumExpectedNumberOfOccurrences']."</minimumExpectedNumberOfOccurrences>\n";
          }
          
          if(!empty($error['dataRange']))
          {
            $xml .= "        <dataRange>".$error['dataRange']."</dataRange>\n";
          }
          
          if(!empty($error['classExpression']))
          {
            $xml .= "        <classExpression>".$error['classExpression']."</classExpression>\n";
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
          $json .= "        \"id\": \"".$error['id']."\"\n";
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
          $json .= "        \"invalidRecordURI\": \"".$error['invalidRecordURI']."\",\n";
          $json .= "        \"invalidPropertyURI\": \"".$error['invalidPropertyURI']."\",\n";
          $json .= "        \"numberOfOccurrences\": \"".$error['numberOfOccurrences']."\",\n";

          if(!empty($error['minimumExpectedNumberOfOccurrences']))
          {
            $json .= "        \"minimumExpectedNumberOfOccurrences\": \"".$error['minimumExpectedNumberOfOccurrences']."\",\n";
          }
          
          if(!empty($error['dataRange']))
          {
            $json .= "        \"dataRange\": \"".$error['dataRange']."\",\n";
          }
          
          if(!empty($error['classExpression']))
          {
            $json .= "        \"classExpression\": \"".$error['classExpression']."\",\n";
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
    
    private function getClassOntology($class) 
    {
      if(isset($this->classOntologyCache[$class]))      
      {
        return($class);
      }
      else
      {
        $crudRead = new CrudReadQuery($this->network);
        
        $classes = array();
        
        foreach($this->checkUsingOntologies as $ontology)
        {
          $classes[] = $class;
        }
        
        $crudRead->uri($classes)
                 ->dataset($this->checkUsingOntologies)
                 ->mime('resultset')
                 ->send();
                 
        if($crudRead->isSuccessful())
        {
          $resultset = $crudRead->getResultset()->getResultset();
    
          $this->classOntologyCache[key($resultset)] = key($resultset);
    
          return($this->classOntologyCache[key($resultset)]);
        }
        else
        {
          return(FALSE);
        }
      }
    }
  }
?>
