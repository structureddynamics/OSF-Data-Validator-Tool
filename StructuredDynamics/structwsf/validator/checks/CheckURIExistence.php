<?php

  namespace StructuredDynamics\structwsf\validator\checks; 

  use \StructuredDynamics\structwsf\php\api\ws\sparql\SparqlQuery;
  use \StructuredDynamics\structwsf\php\api\ws\crud\read\CrudReadQuery;
  use \StructuredDynamics\structwsf\php\api\ws\crud\update\CrudUpdateQuery;  
  
  use \StructuredDynamics\structwsf\framework\Resultset;
  
  class CheckURIExistence extends Check
  {
    private $deletedNTriples = array();    
    
    function __construct()
    {
      $this->name = 'URI Usage Existence Check';
      $this->description = 'Make sure that all the referenced URIs exists in one of the input dataset or ontology';
    }
    
    public function run()
    {
      cecho("\n\n");
      
      cecho("Data validation test: ".$this->description."...\n\n", 'LIGHT_BLUE');

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
             ->query('select distinct ?o ?p
                      '.$from.'
                      where
                      {
                        graph ?g {
                          ?s ?p ?o  .
                          filter(isIRI(?o)) .
                          filter(str(?p) != "http://www.w3.org/1999/02/22-rdf-syntax-ns#value" && str(?p) != "http://purl.org/dc/terms/isPartOf" && str(?p) != "http://www.w3.org/2000/01/rdf-schema#isDefinedBy") .
                        }
                        
                        filter not exists { ?o a ?type }  
                      }')
             ->send();
             
      if($sparql->isSuccessful())
      {
        $results = json_decode($sparql->getResultset(), TRUE);    
        
        if(isset($results['results']['bindings']) && count($results['results']['bindings']) > 0)
        {
          foreach($results['results']['bindings'] as $result)
          {
            $uri = $result['o']['value'];
            $predicate = $result['p']['value'];
            
            if($predicate != 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type')
            {
              cecho('  -> '.$uri."\n", 'LIGHT_RED');
            }
            else
            {
              cecho('  -> '.$uri." (reported; can't fix)\n", 'LIGHT_RED');
            }
            
            $this->errors[] = array(
              'id' => 'URI-EXISTENCE-100',
              'type' => 'error',
              'uri' => $uri
            );
          }

          cecho("\n\n  Note: All the URIs listed above have been used in one of the dataset but are not defined in any of the input datasets or ontologies. These issues need to be investigated, and fixes in the dataset or ontologies may be required.\n\n\n", 'LIGHT_RED');
        }
        else
        {
            cecho("No issues found!\n\n\n", 'LIGHT_GREEN');
        }
      }    
      else
      {
        cecho("We couldn't check if referenced URIs exists in the structWSF instance\n", 'YELLOW');        
        
        $this->errors[] = array(
          'id' => 'URI-EXISTENCE-50',
          'type' => 'warning',
        );         
      }  
    }
    
    public function fix()
    {
      // #1: Get the list of all affected records for all the URI Existence errors
      $affectedRecords = array();
      
      foreach($this->errors as $error)
      {
        if($error['id'] == 'URI-EXISTENCE-100')
        {
          // #1: Get the list of all the affected records for each unexisting, used, URIs
          $affectedRecords = $this->getAffectedRecords($error['uri']);          
          
          foreach($affectedRecords as $dataset => $records)
          {
            foreach($records as $uri)
            {
              // #2: Fix the record by reading/updating it. Create a revision for each of them.
              $this->fixURIReference($error['uri'], $uri, $dataset);
            }
          }
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
          $xml .= "        <unexistingURI>".$error['uri']."</unexistingURI>\n";
          $xml .= "      </error>\n";
        }
      }
      
      $xml .= "    </validationErrors>\n";
      
      $xml .= "    <fixes>\n";
      
      foreach($this->deletedNTriples as $dataset => $records)
      {
        foreach($records as $record => $properties)
        {
          foreach($properties as $property => $values)
          {
            foreach($values as $value)
            {
              $xml .= "      <fix>\n";
              $xml .= "        <dataset>".$dataset."</dataset>\n";
              $xml .= "        <subject>".$record."</subject>\n";
              $xml .= "        <predicate>".$property."</predicate>\n";
              $xml .= "        <object>".$value."</object>\n";
              $xml .= "      </fix>\n";
            }
          }
        }
      }
      
      $xml .= "    </fixes>\n";
      
      
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
          $json .= "        \"unexistingURI\": \"".$error['uri']."\"\n";
          $json .= "      },\n";
          
          $foundErrors = TRUE;
        }
      }
      
      if($foundErrors)
      {
        $json = substr($json, 0, strlen($json) - 2)."\n";
      }

      if(count($this->deletedNTriples) > 0)
      {
        $json .= "    ],\n";
        
        $json .= "    \"fixes\": [\n";
        
        foreach($this->deletedNTriples as $dataset => $records)
        {
          foreach($records as $record => $properties)
          {
            foreach($properties as $property => $values)
            {
              foreach($values as $value)
              {
                $json .= "      {\n";
                $json .= "        \"dataset\": \"".$dataset."\",\n";
                $json .= "        \"subject\": \"".$record."\",\n";
                $json .= "        \"predicate\": \"".$property."\",\n";
                $json .= "        \"object\": \"".$value."\"\n";
                $json .= "      },\n";
              }
            }
          }
        }
        
        $json = substr($json, 0, strlen($json) - 2)."\n";
        
        $json .= "    ]\n";
      }
      else
      {
        $json .= "    ]\n";
      }
      
      $json .= "  }\n";
      
      return($json);      
    } 
    
    private function getAffectedRecords($uri)
    {
      $affectedRecords = array();
                              
      $sparqlAffectedRecords = new SparqlQuery($this->network, $this->appID, $this->apiKey, $this->user);

      $from = '';
      
      foreach($this->checkOnDatasets as $dataset)
      {
        $from .= 'from named <'.$dataset.'> ';
      }
      
      $sparqlAffectedRecords->mime("application/sparql-results+json")
             ->query('select distinct ?s ?g
                      '.$from.'
                      where
                      {
                        graph ?g {
                          ?s ?p <'.$uri.'> .   
                        }
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
            $g = $result['g']['value'];
            
            if(!isset($affectedRecords[$g]))
            {
              $affectedRecords[$g] = array();
            }
            
            $affectedRecords[$g][] = $s;
          }
        }                                
      }      
      else
      {
        cecho("We couldn't get the list of affected records from the structWSF instance\n", 'YELLOW');
        
        $this->errors[] = array(
          'id' => 'URI-EXISTENCE-51',
          'type' => 'warning',
        );          
      }         
      
      return($affectedRecords);
    }

    private function fixURIReference($unexistingURI, $affectedURI, $dataset)
    {
      $crudRead = new CrudReadQuery($this->network, $this->appID, $this->apiKey, $this->user);
      
      $crudRead->dataset($dataset)
               ->uri($affectedURI)
               ->excludeLinksback()
               ->includeReification()
               ->mime('resultset')
               ->send();
               
      if($crudRead->isSuccessful())
      {
        $resultset = $crudRead->getResultset()->getResultset();
        
        // Remove that triple from the record's description
        foreach($resultset[$dataset][$affectedURI] as $property => $values)
        {
          if(is_array($values) && $property != 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type')
          {
            foreach($values as $key => $value)
            {
              if(isset($value['uri']) &&
                 $value['uri'] == $unexistingURI)
              {                
                unset($resultset[$dataset][$affectedURI][$property][$key]);
                
                $rset = new Resultset($this->network);
                
                $rset->setResultset($resultset);
                
                // Use the CRUD: Update endpoint to do the modifications. That way we will revision all the changes
                // performed by this fix procedure.
                
                $crudUpdate = new CrudUpdateQuery($this->network, $this->appID, $this->apiKey, $this->user);                
                
                $crudUpdate->dataset($dataset)
                           ->createRevision()
                           ->isPublished()
                           ->document($rset->getResultsetRDFN3())
                           ->documentMimeIsRdfN3()
                           ->send();
                           
                if($crudUpdate->isSuccessful())           
                {
                  cecho('  -> <'.$dataset.'> <'.$affectedURI.'> <'.$property.'> <'.$unexistingURI."> (fixed)\n", 'LIGHT_BLUE');
                  
                  if(!isset($this->deletedNTriples[$dataset]))
                  {
                    $this->deletedNTriples[$dataset] = array();
                  }
                  
                  if(!isset($this->deletedNTriples[$dataset][$affectedURI]))
                  {
                    $this->deletedNTriples[$dataset][$affectedURI] = array();
                  }
                  
                  if(!isset($this->deletedNTriples[$dataset][$affectedURI][$property]))
                  {
                    $this->deletedNTriples[$dataset][$affectedURI][$property] = array();
                  }
                  
                  $this->deletedNTriples[$dataset][$affectedURI][$property][] = $unexistingURI;
                }
                else
                {
                  cecho("We couldn't update the description of an affected record from the structWSF instance\n", 'YELLOW');
                  
                  $this->errors[] = array(
                    'id' => 'URI-EXISTENCE-53',
                    'type' => 'warning',
                    ''
                  );          
                }
              }
            }
          }
        }
      }
      else
      {
        cecho("We couldn't read the description of an affected record from the structWSF instance\n", 'YELLOW');
        
        $this->errors[] = array(
          'id' => 'URI-EXISTENCE-52',
          'type' => 'warning',
        );          
      }
    }    
  }
?>
