<?php

  namespace StructuredDynamics\structwsf\validator\checks; 

  use \StructuredDynamics\structwsf\php\api\ws\sparql\SparqlQuery;
  
  class CheckSCOMinimumCardinality extends Check
  {
    function __construct()
    { 
      $this->name = 'SCO Minimum Cardinality Check';
      $this->description = 'Make sure that all properties\' SCO min cardinality defined in the ontologies is respected in the datasets';
    }
    
    public function run()
    {
      cecho("\n\n");
      
      cecho("Data validation test: ".$this->description."...\n\n", 'LIGHT_BLUE');

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
      
      $sparql->mime("application/sparql-results+json")
             ->query('select distinct ?s ?o
                      '.$from.'
                      where
                      {
                        ?s <http://purl.org/ontology/sco#minCardinality> ?o
                      }')
             ->send();
             
      if($sparql->isSuccessful())
      {
        $results = json_decode($sparql->getResultset(), TRUE);    
        
        if(isset($results['results']['bindings']) && count($results['results']['bindings']) > 0)
        {
          cecho("Here is the list of all the properties that have a minimum cardinality defined in one of the ontologies:\n\n", 'LIGHT_BLUE');
          
          $minCadinalities = array();
          
          foreach($results['results']['bindings'] as $result)
          {
            $uri = $result['s']['value'];
            $minCardinality = $result['o']['value'];
            
            cecho('  -> '.$uri, 'LIGHT_BLUE');
            cecho(" (minimum cadinality: $minCardinality)\n", 'YELLOW');
            
            $minCadinalities[$uri] = $minCardinality;
          }
          
          cecho("\n\n");
          
          foreach($minCadinalities as $property => $minCardinality)
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
            
            $sparql->mime("application/sparql-results+json")
                   ->query('select ?s count(?s) as ?numberOfOccurrences
                            '.$from.'
                            where
                            {
                              ?s <'.$property.'> ?o .
                            }
                            group by ?s
                            having(count(?s) > '.$minCardinality.')')
                   ->send();
                   
            if($sparql->isSuccessful())
            {
              $results = json_decode($sparql->getResultset(), TRUE);    
              
              if(isset($results['results']['bindings']) && count($results['results']['bindings']) > 0)
              {
                foreach($results['results']['bindings'] as $result)
                {
                  $subject = $result['s']['value'];
                  $numberOfOccurrences = $result['numberOfOccurrences']['value'];
                  
                  cecho('  -> record: '.$subject."\n", 'LIGHT_RED');
                  cecho('     -> property: '.$property."\n", 'LIGHT_RED');
                  cecho('        -> number of occurrences: '.$numberOfOccurrences."\n", 'LIGHT_RED');
                  
                  $this->errors[] = array(
                    'id' => 'SCO-MIN-CARDINALITY-100',
                    'type' => 'error',
                    'invalidRecordURI' => $subject,
                    'invalidPropertyURI' => $property,
                    'numberOfOccurrences' => $numberOfOccurrences,
                    'minExpectedNumberOfOccurrences' => $minCadinalities[$property]
                  );                  
                }
              }
            }
            else
            {
              cecho("We couldn't get the number of properties per record from the structWSF instance\n", 'YELLOW');        
              
              $this->errors[] = array(
                'id' => 'SCO-MIN-CARDINALITY-51',
                'type' => 'warning',
              ); 
            }
          }
          
          if(count($this->errors) > 0)
          {
            cecho("\n\n  Note: All the errors returned above list records that are being described using not enough of a certain type of property. The ontologies does specify that a minimum cardinality should be used for these properties, and what got indexed in the system goes against this instruction of the ontology.\n\n\n", 'LIGHT_RED');
          }
          else
          {
            cecho("\n\n  All properties respects the minimum cardinality specified in the ontologies...\n\n\n", 'LIGHT_GREEN');
          }           
        }
        else
        {
          cecho("No properties have any minimum cardinality defined in any ontologies. Move on to the next check...\n\n\n", 'LIGHT_GREEN');
        }
      }
      else
      {
        cecho("We couldn't get the list of minimum cardinality from the structWSF instance\n", 'YELLOW');        
        
        $this->errors[] = array(
          'id' => 'SCO-MIN-CARDINALITY-50',
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
          $xml .= "        <invalidRecordURI>".$error['invalidRecordURI']."</invalidRecordURI>\n";
          $xml .= "        <invalidPropertyURI>".$error['invalidPropertyURI']."</invalidPropertyURI>\n";
          $xml .= "        <numberOfOccurrences>".$error['numberOfOccurrences']."</numberOfOccurrences>\n";
          $xml .= "        <minExpectedNumberOfOccurrences>".$error['minExpectedNumberOfOccurrences']."</minExpectedNumberOfOccurrences>\n";
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
          $json .= "        \"minExpectedNumberOfOccurrences\": \"".$error['minExpectedNumberOfOccurrences']."\"\n";
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
  }
?>
