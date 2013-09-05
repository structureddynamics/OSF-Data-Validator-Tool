<?php

  namespace StructuredDynamics\structwsf\validator\checks; 

  use \StructuredDynamics\structwsf\php\api\ws\sparql\SparqlQuery;
  use \StructuredDynamics\structwsf\php\api\ws\ontology\read\OntologyReadQuery;
  use \StructuredDynamics\structwsf\php\api\ws\ontology\read\GetSubClassesFunction;
  use \StructuredDynamics\structwsf\php\api\ws\crud\read\CrudReadQuery;
  
  
  class CheckOwlRestrictionMax extends Check
  {
    private $classOntologyCache = array();
    
    function __construct()
    { 
      $this->name = 'OWL Maximum Cardinality Restriction Check';
      $this->description = 'Make sure that all OWL Maximum Cardinality Restriction defined in the ontologies is respected in the datasets';
    }
    
    public function run()
    {
      cecho("\n\n");
      
      cecho("Data validation test: ".$this->description."...\n\n", 'LIGHT_BLUE');

      // Check for Max Cardinality restriction on Datatype Properties    
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
                                         <http://www.w3.org/2002/07/owl#maxQualifiedCardinality> ?cardinality .

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
          cecho("Here is the list of all the OWL max cardinality restrictions defined for the classes currently used in the target datasets applied on datatype properties:\n\n", 'LIGHT_BLUE');
          
          $maxCardinalities = array();
          
          foreach($results['results']['bindings'] as $result)
          {
            $class = $result['class']['value'];
            $maxCardinality = $result['cardinality']['value'];
            $onProperty = $result['onProperty']['value'];
            
            $dataRange = '';
            if(isset($result['dataRange']))
            {
              $dataRange = $result['dataRange']['value'];
            }
            
            cecho('  -> Record of type "'.$class.'" have a maximum cardinality of "'.$maxCardinality.'" when using datatype property "'.$onProperty.'" with value type "'.$dataRange.'"'."\n", 'LIGHT_BLUE');
            
            if(!isset($maxCardinalities[$class]))
            {
              $maxCardinalities[$class] = array();
            }
            
            $maxCardinalities[$class][] = array(
              'maxCardinality' => $maxCardinality,
              'onProperty' => $onProperty,
              'dataRange' => $dataRange
            );
          }

          cecho("\n\n");

          foreach($maxCardinalities as $class => $maxCards)
          {
            foreach($maxCards as $maxCardinality)
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
              if(!empty($maxCardinality['dataRange']))
              {
                if($maxCardinality['dataRange'] == 'http://www.w3.org/2000/01/rdf-schema#Literal')
                {
                  $datatypeFilter = 'filter((str(datatype(?value)) = \'http://www.w3.org/2000/01/rdf-schema#Literal\' || str(datatype(?value)) = \'http://www.w3.org/2001/XMLSchema#string\') && ?onProperty in (<'.$maxCardinality['onProperty'].'>))';
                }
                else
                {
                  $datatypeFilter = 'filter((str(datatype(?value)) = \''.$maxCardinality['dataRange'].'\') && ?onProperty in (<'.$maxCardinality['onProperty'].'>))';
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
                              having(count(?onProperty) > '.$maxCardinality['maxCardinality'].')                            
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
                    cecho('     -> property: '.$maxCardinality['onProperty']."\n", 'LIGHT_RED');
                    cecho('        -> number of occurrences: '.$numberOfOccurrences."\n", 'LIGHT_RED');
                    
                    $this->errors[] = array(
                      'id' => 'OWL-RESTRICTION-MAX-100',
                      'type' => 'error',
                      'invalidRecordURI' => $subject,
                      'invalidPropertyURI' => $maxCardinality['onProperty'],
                      'numberOfOccurrences' => $numberOfOccurrences,
                      'maximumExpectedNumberOfOccurrences' => $maxCardinality['maxCardinality'],
                      'dataRange' => $maxCardinality['dataRange']
                    );                  
                  }
                }
              }
              else
              {
                cecho("We couldn't get the number of properties used per record from the structWSF instance\n", 'YELLOW');
                
                // Error: can't get the number of properties used per record
                $this->errors[] = array(
                  'id' => 'OWL-RESTRICTION-MAX-51',
                  'type' => 'warning',
                );                     
              }
            }
          }
          
          if(count($this->errors) <= 0)
          {
            cecho("\n\n  All records respects the maximum restrictions cardinality specified in the ontologies...\n\n\n", 'LIGHT_GREEN');
          }           
        }
        else
        {
          cecho("No classes have any maximum restriction cardinality defined in any ontologies. Move on to the next check...\n\n\n", 'LIGHT_GREEN');
        }
      }
      else
      {
        cecho("We couldn't get list of maximum cardinality restriction from the structWSF instance\n", 'YELLOW');
        
        // Error: can't get the list of retrictions
        $this->errors[] = array(
          'id' => 'OWL-RESTRICTION-MAX-50',
          'type' => 'warning',
        );                     
      }
      
      // Check for Max Cardinality restriction on Object Properties      
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
                                         <http://www.w3.org/2002/07/owl#maxCardinality> ?cardinality .
                                         
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
          cecho("Here is the list of all the OWL max cardinality restrictions defined for the classes currently used in the target datasets applied on object properties:\n\n", 'LIGHT_BLUE');
          
          $maxCardinalities = array();
          
          foreach($results['results']['bindings'] as $result)
          {
            $class = $result['class']['value'];
            $maxCardinality = $result['cardinality']['value'];
            $onProperty = $result['onProperty']['value'];
            
            $classExpression = '';
            if(isset($result['classExpression']))
            {
              $classExpression = $result['classExpression']['value'];
            }            
            
            cecho('  -> Record of type "'.$class.'" have a maximum cardinality of "'.$maxCardinality.'" when using object property "'.$onProperty.'"'."\n", 'LIGHT_BLUE');
            
            if(!isset($maxCardinalities[$class]))
            {
              $maxCardinalities[$class] = array();
            }
            
            $maxCardinalities[$class][] = array(
              'maxCardinality' => $maxCardinality,
              'onProperty' => $onProperty,
              'classExpression' => $classExpression
            );
          }

          cecho("\n\n");

          foreach($maxCardinalities as $class => $maxCards)
          {
            foreach($maxCards as $maxCardinality)
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
              if(!empty($maxCardinality['classExpression']) && $maxCardinality['classExpression'] != 'http://www.w3.org/2002/07/owl#Thing')
              {
                $subClasses = array($maxCardinality['classExpression']);
                
                // Get all the classes that belong to the class expression defined in this restriction
                $getSubClassesFunction = new GetSubClassesFunction();
                
                $getSubClassesFunction->getClassesUris()
                                      ->allSubClasses()
                                      ->uri($maxCardinality['classExpression']);
                                        
                $ontologyRead = new OntologyReadQuery($this->network);
                
                $ontologyRead->ontology($this->getClassOntology($maxCardinality['classExpression']))
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
                    $classExpressionFilter .= " filter(?value_type in (<".implode(">, <", $subClasses).">)) \n";
                  }
                }
                else
                {
                  cecho("We couldn't get sub-classes of class expression from the structWSF instance\n", 'YELLOW');
                  
                  $this->errors[] = array(
                    'id' => 'OWL-RESTRICTION-MAX-53',
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
                                
                                ?value a ?value_type .

                                '.$classExpressionFilter.'
                                   
                                filter(?onProperty in (<'.$maxCardinality['onProperty'].'>)) .
                              }
                              group by ?s ?onProperty
                              having(count(?onProperty) > '.$maxCardinality['maxCardinality'].')                            
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
                    cecho('     -> property: '.$maxCardinality['onProperty']."\n", 'LIGHT_RED');
                    cecho('        -> number of occurrences: '.$numberOfOccurrences."\n", 'LIGHT_RED');
                    
                    $this->errors[] = array(
                      'id' => 'OWL-RESTRICTION-MAX-101',
                      'type' => 'error',
                      'invalidRecordURI' => $subject,
                      'invalidPropertyURI' => $maxCardinality['onProperty'],
                      'numberOfOccurrences' => $numberOfOccurrences,
                      'maximumExpectedNumberOfOccurrences' => $maxCardinality['maxCardinality'],
                      'classExpression' => $maxCardinality['classExpression']
                    );                  
                  }
                }
              }
              else
              {
                cecho("We couldn't get properties counts of records from the structWSF instance\n", 'YELLOW');
                
                $this->errors[] = array(
                  'id' => 'OWL-RESTRICTION-MAX-54',
                  'type' => 'warning',
                );           
              }
            }
            
            if(count($this->errors) > 0)
            {
  //            cecho("\n\n  Note: All the errors returned above list records that are being described using not enough of a certain type of property. The ontologies does specify that a minimum cardinality should be used for these properties, and what got indexed in the system goes against this instruction of the ontology.\n\n\n", 'LIGHT_RED');
            }
            else
            {
  //            cecho("\n\n  All properties respects the minimum cardinality specified in the ontologies...\n\n\n", 'LIGHT_GREEN');
            }           
          }
        }
        else
        {
//          cecho("No properties have any minimum cardinality defined in any ontologies. Move on to the next check...\n\n\n", 'LIGHT_GREEN');
        }
      }      
      else
      {
        cecho("We couldn't get max cardinality restriction on object properties from the structWSF instance\n", 'YELLOW');
        
        $this->errors[] = array(
          'id' => 'OWL-RESTRICTION-MAX-52',
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
          
          if(!empty($error['maximumExpectedNumberOfOccurrences']))
          {
            $xml .= "        <maximumExpectedNumberOfOccurrences>".$error['maximumExpectedNumberOfOccurrences']."</maximumExpectedNumberOfOccurrences>\n";
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

          if(!empty($error['maximumExpectedNumberOfOccurrences']))
          {
            $json .= "        \"maximumExpectedNumberOfOccurrences\": \"".$error['maximumExpectedNumberOfOccurrences']."\",\n";
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
