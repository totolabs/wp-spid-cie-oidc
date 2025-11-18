<?php
// Carica l'autoloader
require_once __DIR__ . '/vendor/autoload.php';

echo "<h2>Diagnostica Percorsi OIDC</h2>";

// 1. Controlliamo se la cartella lib esiste
$lib_path = __DIR__ . '/lib/spid-cie-oidc-php/lib/SpidCieOidc.php';
echo "<strong>Cercando il file della classe qui:</strong> <br>" . $lib_path . "<br><br>";

if (file_exists($lib_path)) {
    echo "<span style='color:green'>[OK] Il file fisico ESISTE.</span><br>";
} else {
    echo "<span style='color:red'>[ERRORE] Il file fisico NON ESISTE.</span><br>";
    echo "Contenuto della cartella /lib/spid-cie-oidc-php/:<br>";
    $dir = __DIR__ . '/lib/spid-cie-oidc-php/';
    if (is_dir($dir)) {
        print_r(scandir($dir));
    } else {
        echo "La cartella " . $dir . " non esiste proprio!";
    }
}

echo "<hr>";

// 2. Controlliamo cosa pensa l'autoloader
$loader = require __DIR__ . '/vendor/autoload.php';
$map = $loader->getPrefixesPsr4();

echo "<strong>Mappa PSR-4 dell'Autoloader:</strong><pre>";
if (isset($map['Italia\\SpidCieOidc\\'])) {
    print_r($map['Italia\\SpidCieOidc\\']);
} else {
    echo "<span style='color:red'>[ERRORE] L'autoloader non ha nessuna regola per 'Italia\\SpidCieOidc\\'!</span>";
    echo "<br>Questo significa che la cartella 'vendor' sul server è vecchia.";
}
echo "</pre>";