<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Technical documentation directory
    |--------------------------------------------------------------------------
    |
    | Where DocsController reads the generated documentation from. This is a
    | NON-public path (outside the web root); the files are reachable only via
    | the authenticated /manual routes.
    |
    */
    'path' => env('DOCS_PATH', storage_path('app/docs')),
];
