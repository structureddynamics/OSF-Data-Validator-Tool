<?php

  namespace StructuredDynamics\osf\validator\checks; 

  use \StructuredDynamics\osf\php\api\ws\sparql\SparqlQuery;
  use \StructuredDynamics\osf\php\api\ws\crud\read\CrudReadQuery;
  use \StructuredDynamics\osf\php\api\ws\crud\update\CrudUpdateQuery;  
  
  use \StructuredDynamics\osf\framework\Resultset;
  
  class CheckClassesDefined extends Check
  {
    function __construct()
    {
      $this->name = 'Classes Defined Check';
      $this->description = 'Make sure all the classes that are used in the datasets are defined in the ontologies.';
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
             ->query('select distinct ?o
                      '.$from.'
                      where
                      {
                        graph ?g {
                          ?s a ?o  .
                        }
                        
                        filter not exists { ?o a <http://www.w3.org/2002/07/owl#Class> }  
                      }')
             ->send();
       
      if($sparql->isSuccessful())
      {
        $results = json_decode($sparql->getResultset(), TRUE);    
        
        if(isset($results['results']['bindings']) && count($results['results']['bindings']) > 0)
        {
          foreach($results['results']['bindings'] as $result)
          {
            $class = $result['o']['value'];
            
            cecho('  -> '.$class."\n", 'LIGHT_RED');
            
            $this->errors[] = array(
              'id' => 'CLASSES-DEFINED-100',
              'type' => 'error',
              'class' => $class
            );
          }

          cecho("\n\n  Note: All the class URIs listed above have been used in one of the dataset but are not defined in any of the input ontologies. These issues need to be investigated, and fixes in the ontologies may be required.\n\n\n", 'LIGHT_RED');
        }
        else
        {
            cecho("No issues found!\n\n\n", 'LIGHT_GREEN');
        }
      }    
      else
      {
        cecho("We couldn't check if used classes URIs exists in the OSF Web Services instance\n", 'YELLOW');        
        
        $this->errors[] = array(
          'id' => 'CLASSES-DEFINED-50',
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
          
          if(!empty($error['class']))
          {
            $xml .= "        <class>".$error['class']."</class>\n";
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
          $xml .= "        <class>".$error['class']."</class>\n";
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
          $json .= "        \"class\": \"".$error['class']."\"\n";
          $json .= "      },\n";
          
          $foundErrors = TRUE;
        }
      }
      
      if($foundErrors)
      {
        $json = substr($json, 0, strlen($json) - 2)."\n";
      }
      else
      {
        $json .= "    ]\n";
      }
      
      $json .= "  }\n";
      
      return($json);      
    } 
  }
?>
