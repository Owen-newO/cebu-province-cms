<?php

/*
|--------------------------------------------------------------------------
| Cebu LGUs — 44 Municipalities + 9 Cities
|--------------------------------------------------------------------------
| slug => Display name. The slug (no spaces, lowercase) matches the S3 folder
| and the value municipalSlug() produces, so it maps straight to
| {slug}/tour.xml and {slug}/{sceneId}/... on S3. Used by the admin dashboard
| municipality selector.
|
| Cities are listed alongside the municipalities in one alphabetical list and
| carry a "City" suffix in the display name. Note 'cebucity' — the plain
| 'cebu' slug is NOT available, it is the S3 disk root for the province tour
| itself (cebu/tour.xml), under which every LGU folder lives.
*/

return [
    'alcantara'    => 'Alcantara',
    'alcoy'        => 'Alcoy',
    'alegria'      => 'Alegria',
    'aloguinsan'   => 'Aloguinsan',
    'argao'        => 'Argao',
    'asturias'     => 'Asturias',
    'badian'       => 'Badian',
    'balamban'     => 'Balamban',
    'bantayan'     => 'Bantayan',
    'barili'       => 'Barili',
    'bogo'         => 'Bogo City',
    'boljoon'      => 'Boljoon',
    'borbon'       => 'Borbon',
    'carcar'       => 'Carcar City',
    'carmen'       => 'Carmen',
    'catmon'       => 'Catmon',
    'cebucity'     => 'Cebu City',
    'compostela'   => 'Compostela',
    'consolacion'  => 'Consolacion',
    'cordova'      => 'Cordova',
    'daanbantayan' => 'Daanbantayan',
    'dalaguete'    => 'Dalaguete',
    'danao'        => 'Danao City',
    'dumanjug'     => 'Dumanjug',
    'ginatilan'    => 'Ginatilan',
    'lapulapu'     => 'Lapu-Lapu City',
    'liloan'       => 'Liloan',
    'madridejos'   => 'Madridejos',
    'malabuyoc'    => 'Malabuyoc',
    'mandaue'      => 'Mandaue City',
    'medellin'     => 'Medellin',
    'minglanilla'  => 'Minglanilla',
    'moalboal'     => 'Moalboal',
    'naga'         => 'Naga City',
    'oslob'        => 'Oslob',
    'pilar'        => 'Pilar',
    'pinamungajan' => 'Pinamungajan',
    'poro'         => 'Poro',
    'ronda'        => 'Ronda',
    'samboan'      => 'Samboan',
    'sanfernando'  => 'San Fernando',
    'sanfrancisco' => 'San Francisco',
    'sanremigio'   => 'San Remigio',
    'santafe'      => 'Santa Fe',
    'santander'    => 'Santander',
    'sibonga'      => 'Sibonga',
    'sogod'        => 'Sogod',
    'tabogon'      => 'Tabogon',
    'tabuelan'     => 'Tabuelan',
    'talisay'      => 'Talisay City',
    'toledo'       => 'Toledo City',
    'tuburan'      => 'Tuburan',
    'tudela'       => 'Tudela',
];
