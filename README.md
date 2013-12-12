OSF-Data-Validator-Tool
===========================================

The Data Validator Tool (DVT) is a command line tool used to perform a series of post indexation data validation tests. What this tool does is to run a series of pre-configured tests, and return validation errors if any are found.


Installing & Configuring the Data Validator Tool
-----------------------------------------------------

The Data Validator Tool can easily be installed on your server by extracting the files into the OSF Web Services folder on your server:

```

  [root-path]/StructuredDynamics/osf/validator/
  
```

The DVT is using the [OSF Web Services PHP API](https://github.com/structureddynamics/OSF-Web-Services-PHP-API) library to communicate with any OSF network instance. If the OSF Web Services PHP API is not currently installed on your server, then follow these steps to download and install it on your server instance:

```bash

  ./osf-installer --install-osf-ws-php-api -v 

```

Once the DVT and the OSF Web Services PHP API are downloaded and properly installed on your server, you then have to configure some key DVT configuration settings:

*   `OSF-WS-PHP-API/folder`

    > Folder where the OSF Web Services PHP API is located. This has to be the folder where the 
    > the top "StructuredDynamics" folder appears.
    
*   `osf/network`

    > Base OSF Web Services network URL where the queries will be sent

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
--allocated-memory="M"                Specifies the number of Mb of memory allocated to the DVT
                                      The number of Mb should be specified in this parameter
-v                                    Run all the data validation tests
-s                                    Silent. Do not output anything to the shell.
-f, --fix                             Tries to automatically fix a validation test that fails
                                      Note: not all checks support this option
-h, --help                            Show this help section
```
