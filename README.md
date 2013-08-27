structWSF-Data-Validator-Tool
===========================================

The Data Validator Tool (DVT) is a command line tool used to perform a series of post indexation data validation tests. What this tool does is to run a series of pre-configured tests, and return validation errors if any are found.


Installing & Configuring the Data Validator Tool
-----------------------------------------------------

The Data Validator Tool can easily be installed on your server by extracting the files into the structwsf folder on your server:

```

  [root-path]/StructuredDynamics/structwsf/validator/
  
```

The DVT is using the [structWSF-PHP-API](https://github.com/structureddynamics/structWSF-PHP-API) library to communicate with any structWSF network instance. If the structWSF-PHP-API is not currently installed on your server, then follow these steps to download and install it on your server instance:

```bash

  ./osf-installer --install-structwsf-php-api -v 

```

Once the DVT and the structWSF-PHP-API are downloaded and properly installed on your server, you then have to configure some key DVT configuration settings:

*   `structWSF-PHP-API/folder`

    > Folder where the structWSF-PHP-API is located. This has to be the folder where the 
    > the top "StructuredDynamics" folder appears.
    
*   `structwsf/network`

    > Base structWSF web services network URL where the queries will be sent

*   `data/datasets`

    > The series of datasets URIs that you want to inspect with this tool

*   `data/ontologies`

    > The series of ontologies dataset URIs that you want to use to inspect the configured datasets

*   `tests/checks`

    > The tests validation classes that will be run by this data validator tool

Usage Documentation
-------------------
```
Usage: dvt [OPTIONS]


Usage examples:
    Validate data: dvt -v
Options:
--output-xml="[PATH]"                 Output the validation reports in a file specified
                                      by the path in XML format.
--output-json="[PATH]"                Output the validation reports in a file specified
                                      by the path in JSON format.
-v                                    Run all the data validation tests
-s                                    Silent. Do not output anything to the shell.
-h, --help                            Show this help section
```
