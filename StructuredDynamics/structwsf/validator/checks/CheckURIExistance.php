<?php

  namespace StructuredDynamics\structwsf\validator\checks; 

  use \StructuredDynamics\structwsf\php\api\ws\sparql\SparqlQuery;
  
  class CheckURIExistance
  {
    function __construct($config)
    { 
      $this->run($config);      
    }
    
    private function run($config)
    {
      cecho("\n\n");
      
      cecho("Data validation test: make sure that all the referenced URIs exists in one of the input dataset or ontology...\n\n", 'LIGHT_BLUE');

      $sparql = new SparqlQuery($config['structwsf']['network']);

      $from = '';
      
      foreach($config['data']['datasets'] as $dataset)
      {
        $from .= 'from <'.$dataset.'> ';
      }
      
      foreach($config['data']['ontologies'] as $ontology)
      {
        $from .= 'from <'.$ontology.'> ';
      }
      
      $sparql->mime("application/sparql-results+json")
             ->query('select distinct ?o
                      '.$from.'
                      where
                      {
                        ?s ?p ?o  .
                        filter(isIRI(?o)) .
                        filter(str(?p) != "http://www.w3.org/2000/01/rdf-schema#range" && str(?p) != "http://www.w3.org/2000/01/rdf-schema#domain" && str(?p) != "http://www.w3.org/1999/02/22-rdf-syntax-ns#value" && str(?p) != "http://purl.org/dc/terms/isPartOf" && str(?p) != "http://www.w3.org/2000/01/rdf-schema#isDefinedBy") .
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
            
            cecho('  -> '.$uri."\n", 'LIGHT_RED');
          }

          cecho("\n\n  Note: All the URIs listed above have been used in one of the dataset but are not defined in any of the input datasets or ontologies. These issues need to be investigated, and fixes in the dataset or ontologies may be required.\n\n\n", 'LIGHT_RED');
        }
        else
        {
            cecho("No issues found!\n\n\n", 'LIGHT_GREEN');
        }
      }      
    }
  }
?>
