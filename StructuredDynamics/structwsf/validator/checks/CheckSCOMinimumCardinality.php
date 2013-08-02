<?php

  namespace StructuredDynamics\structwsf\validator\checks; 

  use \StructuredDynamics\structwsf\php\api\ws\sparql\SparqlQuery;
  
  class CheckSCOMinimumCardinality
  {
    function __construct($config)
    { 
      $this->run($config);      
    }
    
    private function run($config)
    {
      cecho("\n\n");
      
      cecho("Data validation test: make sure that all properties' SCO min cardinality defined in the ontologies is respected in the datasets...\n\n", 'LIGHT_BLUE');

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
          
          $errorsFound = FALSE;
          
          foreach($minCadinalities as $property => $minCardinality)
          {
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
                   ->query('select ?s count(?s) as ?numberOfOccurences
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
                  $numberOfOccurences = $result['numberOfOccurences']['value'];
                  
                  cecho('  -> record: '.$subject."\n", 'LIGHT_RED');
                  cecho('     -> property: '.$property."\n", 'LIGHT_RED');
                  cecho('        -> number of occurences: '.$numberOfOccurences."\n", 'LIGHT_RED');
                }
                
                $errorsFound = TRUE;
              }
            }
          }
          
          if($errorsFound)
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
    }
  }
?>
