<?php

  namespace StructuredDynamics\structwsf\validator\checks; 

  use \StructuredDynamics\structwsf\php\api\ws\sparql\SparqlQuery;
  
  class CheckSCOMaximumCardinality extends Check
  {
    function __construct()
    { 
      $this->name = 'SCO Maximum Cardinality Check';
      $this->description = 'Make sure that all properties\' SCO max cardinality defined in the ontologies is respected in the datasets';
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
                        ?s <http://purl.org/ontology/sco#maxCardinality> ?o
                      }')
             ->send();
             
      if($sparql->isSuccessful())
      {
        $results = json_decode($sparql->getResultset(), TRUE);    
        
        if(isset($results['results']['bindings']) && count($results['results']['bindings']) > 0)
        {
          cecho("Here is the list of all the properties that have a maximum cardinality defined in one of the ontologies:\n\n", 'LIGHT_BLUE');
          
          $maxCadinalities = array();
          
          foreach($results['results']['bindings'] as $result)
          {
            $uri = $result['s']['value'];
            $maxCardinality = $result['o']['value'];
            
            cecho('  -> '.$uri, 'LIGHT_BLUE');
            cecho(" (maximum cadinality: $maxCardinality)\n", 'YELLOW');
            
            $maxCadinalities[$uri] = $maxCardinality;
          }
          
          cecho("\n\n");
          
          foreach($maxCadinalities as $property => $maxCardinality)
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
                   ->query('select ?s count(?s) as ?numberOfOccurences
                            '.$from.'
                            where
                            {
                              ?s <'.$property.'> ?o .
                            }
                            group by ?s
                            having(count(?s) > '.$maxCardinality.')')
                   ->send();
                   
            if($sparql->isSuccessful())
            {
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
                    'type' => 'error',
                    'invalidRecordURI' => $subject,
                    'invalidPropertyURI' => $property,
                    'numberOfOccurences' => $numberOfOccurences,
                    'maxExpectedNumberOfOccurences' => $maxCadinalities[$property]
                  );                  
                }
              }
            }
          }
          
          if(count($this->errors) > 0)
          {
            cecho("\n\n  Note: All the errors returned above list records that are being described using too many of a certain type of property. The ontologies does specify that a maximum cardinality should be used for these properties, and what got indexed in the system goes against this instruction of the ontology.\n\n\n", 'LIGHT_RED');
          }
          else
          {
            cecho("\n\n  All properties respects the maximum cardinality specified in the ontologies...\n\n\n", 'LIGHT_GREEN');
          }           
        }
        else
        {
          cecho("No properties have any maximum cardinality defined in any ontologies. Move on to the next check...\n\n\n", 'LIGHT_GREEN');
        }
      }
    }
    
    public function outputXML()
    {
      /*
        <check>
          
          <name></name>
          <description></description>
          <onDatasets></onDatasets>
          <usingOntologies></usingOntologies>
          
          <validationErrors>
            <error>
              <id></id>
              <invalidRecordURI></invalidRecordURI>
              <invalidPropertyURI></invalidPropertyURI>
              <numberOfOccurences></numberOfOccurences>
              <maxExpectedNumberOfOccurences></maxExpectedNumberOfOccurences>
            </error>
          <validationErrors>
          
        </check>      
      */
      
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
      
      $xml .= "    <validationErrors>\n";
      
      foreach($this->errors as $error)
      {
        if($error['type'] == 'error')
        {
          $xml .= "      <error>\n";
          $xml .= "        <id>".$error['id']."</id>\n";
          $xml .= "        <invalidRecordURI>".$error['invalidRecordURI']."</invalidRecordURI>\n";
          $xml .= "        <invalidPropertyURI>".$error['invalidPropertyURI']."</invalidPropertyURI>\n";
          $xml .= "        <numberOfOccurences>".$error['numberOfOccurences']."</numberOfOccurences>\n";
          $xml .= "        <maxExpectedNumberOfOccurences>".$error['maxExpectedNumberOfOccurences']."</maxExpectedNumberOfOccurences>\n";
          $xml .= "      </error>\n";
        }
      }
      
      $xml .= "    </validationErrors>\n";
      
      $xml .= "  </check>\n";
      
      return($xml);
    }    
    
    public function outputJSON()
    {
      /*
        {
          "name": "",
          "description": "",
          "onDatasets": [],
          "usingOntologies": [],
          "validationErrors": [
            {
              "id": "",
              "invalidRecordURI": ""
              "invalidPropertyURI": ""
              "numberOfOccurences": ""
              "maxExpectedNumberOfOccurences": ""
            }
          ]
        }  
      */

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
      
      $foundErrors = FALSE;
      foreach($this->errors as $error)
      {
        if($error['type'] == 'error')
        {
          $json .= "      {\n";
          $json .= "        \"id\": \"".$error['id']."\",\n";
          $json .= "        \"invalidRecordURI\": \"".$error['invalidRecordURI']."\",\n";
          $json .= "        \"invalidPropertyURI\": \"".$error['invalidPropertyURI']."\",\n";
          $json .= "        \"numberOfOccurences\": \"".$error['numberOfOccurences']."\",\n";
          $json .= "        \"maxExpectedNumberOfOccurences\": \"".$error['maxExpectedNumberOfOccurences']."\"\n";
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
