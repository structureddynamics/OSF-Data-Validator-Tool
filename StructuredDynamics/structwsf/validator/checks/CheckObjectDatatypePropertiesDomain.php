<?php

  namespace StructuredDynamics\structwsf\validator\checks; 

  use \StructuredDynamics\structwsf\php\api\ws\sparql\SparqlQuery;
  use \StructuredDynamics\structwsf\php\api\ws\ontology\read\OntologyReadQuery;
  use \StructuredDynamics\structwsf\php\api\ws\ontology\read\GetSuperClassesFunction;
  use \StructuredDynamics\structwsf\php\api\ws\crud\read\CrudReadQuery;
  
  class CheckObjectDatatypePropertiesDomain extends Check
  {
    private $typeOntologyCache = array();
    
    function __construct()
    { 
      $this->name = 'Object & Datatype Properties Domain Check';
      $this->description = 'Make sure that all the object & datatype properties used to describe the records uses the proper domain as defined in the ontologies';
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
      
      // Get the list of all the object & datatype properties used within the datasets
      $sparql->mime("application/sparql-results+json")
             ->query('select distinct ?p ?domain
                      '.$from.'
                      where
                      {
                        graph ?g {
                          ?s ?p ?o .
                        } 
                        
                        optional
                        {                                                          
                          ?p <http://www.w3.org/2000/01/rdf-schema#domain> ?domain .
                        }
                        
                        filter(str(?p) != "http://purl.org/dc/terms/isPartOf" && str(?p) != "http://www.w3.org/1999/02/22-rdf-syntax-ns#value" && str(?p) != "http://www.w3.org/1999/02/22-rdf-syntax-ns#type")
                      }')
             ->send();

      if($sparql->isSuccessful())
      {
        $results = json_decode($sparql->getResultset(), TRUE);    

        if(isset($results['results']['bindings']) && count($results['results']['bindings']) > 0)
        {
          $objectDatatypeProperties = array();
          $thereAreEmptyDomains = FALSE;

          foreach($results['results']['bindings'] as $result)
          {
            $objectDatatypeProperty = $result['p']['value'];
            
            $objectDatatypeProperties[$objectDatatypeProperty] = '';
            
            if(isset($result['domain']))
            {
              $objectDatatypeProperties[$objectDatatypeProperty] = $result['domain']['value'];
            }
            else
            {
              $thereAreEmptyDomains = TRUE;
            }
          }

          // Display warnings
          if($thereAreEmptyDomains)
          {
            cecho("The following object & datatype properties are used to describe records, but their domain is not specified in the ontologies. owl:Thing is assumed as the range, but you may want to define it further and re-run this check:\n", 'YELLOW');
            
            foreach($objectDatatypeProperties as $objectDatatypeProperty => $domain)
            {
              if(empty($domain))
              {
                cecho('  -> property: '.$objectDatatypeProperty."\n", 'YELLOW');
                
                $this->errors[] = array(
                  'id' => 'OBJECT-DATATYPE-PROPERTIES-DOMAIN-50',
                  'type' => 'warning',
                  'property' => $objectDatatypeProperty,
                );                
              }
            }
          }

          // Now, for each object & datatype properties that have a domain defined,
          // we:
          // 
          //  (a) List all domains used for a given property
          //  (b) For each of these domains, make sure they comply with what is defined in the ontology as
          //      the domain of the property
          foreach($objectDatatypeProperties as $objectDatatypeProperty => $domain)
          {
            // If the domain is empty, we consider it owl:Thing.
            // If the domain is owl:Thing, then we simply skip this check since everything is an owl:Thing
            if(!empty($domain) && $domain != 'http://www.w3.org/2002/07/owl#Thing')
            {
              $types = array();
              
              $sparql = new SparqlQuery($this->network);

              $from = '';
              
              foreach($this->checkOnDatasets as $dataset)
              {
                $from .= 'from <'.$dataset.'> ';
              }
              
              $sparql->mime("application/sparql-results+json")
                     ->query('select distinct ?type
                              '.$from.'
                              where
                              {
                                ?s a ?type .
                                ?s <'.$objectDatatypeProperty.'> ?o.
                              }')
                     ->send();
               
              if($sparql->isSuccessful())
              {
                // Create the array of types
                $results = json_decode($sparql->getResultset(), TRUE);    
                
                if(isset($results['results']['bindings']) && count($results['results']['bindings']) > 0)
                {
                  foreach($results['results']['bindings'] as $result)
                  {
                    $type = $result['type']['value'];
                    
                    if(!in_array($type, $types))
                    {
                      $types[] = $type;
                    }
                  }
                }

                // For each type, we do validate that the domain is valid
                foreach($types as $type)
                {
                  // Then, check if the $domain and the $type directly matches
                  if($type == $domain)
                  {
                    continue;
                  }
                  else
                  {
                    // If they are not, then we check in the ontology to see if the domain is not
                    // one of the super class of the type
                    $superClasses = array();

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
                        cecho("We couldn't get the list of super-classes of a target type from the structWSF instance\n", 'YELLOW');
                        
                        // Log a warning
                        // Can't get the super classes of the target type
                        $this->errors[] = array(
                          'id' => 'OBJECT-DATATYPE-PROPERTIES-DOMAIN-51',
                          'type' => 'warning',
                          'property' => $objectDatatypeProperty,
                        );                              
                      }
                    }
                    else
                    {
                      cecho("We couldn't find the ontology where the $type is defined on the structWSF instance\n", 'YELLOW');
                      
                      // Log a warning
                      // Can't find ontology where the type $type is defined
                      $this->errors[] = array(
                        'id' => 'OBJECT-DATATYPE-PROPERTIES-DOMAIN-52',
                        'type' => 'warning'
                      );                              
                    }

                    
                    $domainMatch = FALSE;
      
                    foreach($superClasses as $superClass)
                    {
                      if($superClass == $domain)
                      {
                        $domainMatch = TRUE;
                        break;
                      }
                    }                            
                    
                    if(!$domainMatch)
                    {
                      // Log an error
                      // Couldn't match one of the super classe with the specified range
                      cecho('  -> Property "'.$objectDatatypeProperty.'" doesn\'t match domain "'.$domain.'" for record type "'.$type.'"'."\n", 'LIGHT_RED');
                      
                      $this->errors[] = array(
                        'id' => 'OBJECT-DATATYPE-PROPERTIES-DOMAIN-100',
                        'type' => 'error',
                        'property' => $objectDatatypeProperty,
                        'definedDomain' => $domain,
                        'type' => $type,
                        'typeSuperTypes' => $superClasses,
                        'affectedRecords' => $this->getAffectedRecords($objectDatatypeProperty, $type, $objectDatatypeProperty)
                      );        
                    }
                  }
                }
              }
              else
              {
                cecho("We couldn't get the list of the domain of the object & datatype properties from the structWSF instance\n", 'YELLOW');
                
                $this->errors[] = array(
                  'id' => 'OBJECT-DATATYPE-PROPERTIES-DOMAIN-54',
                  'type' => 'warning',
                );                 
              }
            }           
          }
        }
      }
      else
      {
        cecho("We couldn't get the list of object & datatype properties from the structWSF instance\n", 'YELLOW');
        
        $this->errors[] = array(
          'id' => 'OBJECT-DATATYPE-PROPERTIES-DOMAIN-53',
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
          
          if(!empty($error['property']))
          {
            $xml .= "        <property>".$error['property']."</property>\n";
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
          $xml .= "        <property>".$error['property']."</property>\n";
          $xml .= "        <definedDomain>".$error['definedDomain']."</definedDomain>\n";
          $xml .= "        <type>".$error['type']."</type>\n";
          
          if(!empty($error['typeSuperTypes']))
          {
            $xml .= "        <typeSuperTypes>\n";
            $xml .= "          <typeSuperTypes>".implode("</typeSuperType>\n          <typeSuperType>", $error['typeSuperTypes'])."</typeSuperType>\n";            
            $xml .= "        </typeSuperTypes>\n";
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
          
          if(!empty($error['property']))
          {
            $json .= "        \"property\": \"".$error['property']."\",\n";  
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
          $json .= "        \"property\": \"".$error['property']."\",\n";
          $json .= "        \"definedDomain\": \"".$error['definedDomain']."\",\n";
          $json .= "        \"type\": \"".$error['type']."\",\n";

          if(!empty($error['typeSuperTypes']))
          {
            $json .= "        \"typeSuperTypes\": [\n";
            $json .= "          \"".implode("\", \n          \"", $error['typeSuperTypes'])."\"\n";
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
        $crudRead = new CrudReadQuery($this->network);
        
        $crudRead->uri($type)
                 ->mime('resultset')
                 ->send();
                 
        if($crudRead->isSuccessful())
        {
          $resultset = $crudRead->getResultset()->getResultset();
    
          $this->typeOntologyCache[key($resultset)] = key($resultset);
    
          return($this->typeOntologyCache[key($resultset)]);
        }
        else
        {
          return(FALSE);
        }
      }
    }
    
    private function getAffectedRecords($objectDatatypeProperty, $type, $objectDatatypeProperty)
    {
      $affectedRecords = array();
                              
      $sparqlAffectedRecords = new SparqlQuery($this->network);

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
                        ?s a <'.$type.'> .
                        ?s <'.$objectDatatypeProperty.'> ?o .
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
        cecho("We couldn't get the list of affected records from the structWSF instance\n", 'YELLOW');
        
        $this->errors[] = array(
          'id' => 'OBJECT-DATATYPE-PROPERTIES-DOMAIN-55',
          'type' => 'warning',
        );          
      }      
      
      return($affectedRecords);
    }
  }
?>
