<?php
// Curated starting sources. Imported on first run; later additions here can be pulled in
// with "Import seed list" on the Sources admin page (existing feeds are never duplicated).
//
// geo:  us | us-ne | us-ny | es | es-vc | es-alc | eu | uk | europe | intl   (see Sources::GEO)
// lang: en | es
// type: newspaper | broadcaster | magazine | digital | wire | public | institution
// cat:  optional default category when an article carries no usable category
// country: only needed where geo doesn't imply it (europe / intl)

return [
    // --- United States · National -------------------------------------------------
    ['name' => 'NPR', 'feed' => 'https://feeds.npr.org/1001/rss.xml', 'home' => 'https://www.npr.org', 'geo' => 'us', 'lang' => 'en', 'type' => 'broadcaster'],
    ['name' => 'PBS News', 'feed' => 'https://www.pbs.org/newshour/feeds/rss/headlines', 'home' => 'https://www.pbs.org/newshour', 'geo' => 'us', 'lang' => 'en', 'type' => 'broadcaster'],
    ['name' => 'CBS News', 'feed' => 'https://www.cbsnews.com/latest/rss/main', 'home' => 'https://www.cbsnews.com', 'geo' => 'us', 'lang' => 'en', 'type' => 'broadcaster'],
    ['name' => 'NBC News', 'feed' => 'https://feeds.nbcnews.com/nbcnews/public/news', 'home' => 'https://www.nbcnews.com', 'geo' => 'us', 'lang' => 'en', 'type' => 'broadcaster'],
    ['name' => 'ABC News', 'feed' => 'https://abcnews.go.com/abcnews/topstories', 'home' => 'https://abcnews.go.com', 'geo' => 'us', 'lang' => 'en', 'type' => 'broadcaster'],
    ['name' => 'Fox News', 'feed' => 'https://moxie.foxnews.com/google-publisher/latest.xml', 'home' => 'https://www.foxnews.com', 'geo' => 'us', 'lang' => 'en', 'type' => 'broadcaster'],
    ['name' => 'Politico', 'feed' => 'https://rss.politico.com/politics.xml', 'home' => 'https://www.politico.com', 'geo' => 'us', 'lang' => 'en', 'type' => 'digital', 'cat' => 'politics'],
    ['name' => 'The Hill', 'feed' => 'https://thehill.com/feed/', 'home' => 'https://thehill.com', 'geo' => 'us', 'lang' => 'en', 'type' => 'digital', 'cat' => 'politics'],
    ['name' => 'ProPublica', 'feed' => 'https://feeds.propublica.org/propublica/main', 'home' => 'https://www.propublica.org', 'geo' => 'us', 'lang' => 'en', 'type' => 'digital'],
    ['name' => 'Vox', 'feed' => 'https://www.vox.com/rss/index.xml', 'home' => 'https://www.vox.com', 'geo' => 'us', 'lang' => 'en', 'type' => 'digital'],
    ['name' => 'The Guardian US', 'feed' => 'https://www.theguardian.com/us-news/rss', 'home' => 'https://www.theguardian.com/us', 'geo' => 'us', 'lang' => 'en', 'type' => 'newspaper'],
    ['name' => 'CNBC', 'feed' => 'https://www.cnbc.com/id/100003114/device/rss/rss.html', 'home' => 'https://www.cnbc.com', 'geo' => 'us', 'lang' => 'en', 'type' => 'broadcaster', 'cat' => 'business'],
    ['name' => 'Ars Technica', 'feed' => 'https://feeds.arstechnica.com/arstechnica/index', 'home' => 'https://arstechnica.com', 'geo' => 'us', 'lang' => 'en', 'type' => 'digital', 'cat' => 'technology'],
    ['name' => 'The Verge', 'feed' => 'https://www.theverge.com/rss/index.xml', 'home' => 'https://www.theverge.com', 'geo' => 'us', 'lang' => 'en', 'type' => 'digital', 'cat' => 'technology'],

    // --- United States · Northeast ---------------------------------------------------
    ['name' => 'Boston.com', 'feed' => 'https://www.boston.com/feed/', 'home' => 'https://www.boston.com', 'geo' => 'us-ne', 'lang' => 'en', 'type' => 'digital', 'cat' => 'local', 'region' => 'Massachusetts'],
    ['name' => 'WHYY', 'feed' => 'https://whyy.org/feed/', 'home' => 'https://whyy.org', 'geo' => 'us-ne', 'lang' => 'en', 'type' => 'broadcaster', 'cat' => 'local', 'region' => 'Philadelphia'],
    ['name' => 'CT Mirror', 'feed' => 'https://ctmirror.org/feed/', 'home' => 'https://ctmirror.org', 'geo' => 'us-ne', 'lang' => 'en', 'type' => 'digital', 'cat' => 'local', 'region' => 'Connecticut'],
    ['name' => 'NJ Spotlight News', 'feed' => 'https://www.njspotlightnews.org/feed/', 'home' => 'https://www.njspotlightnews.org', 'geo' => 'us-ne', 'lang' => 'en', 'type' => 'digital', 'cat' => 'local', 'region' => 'New Jersey'],
    ['name' => 'VTDigger', 'feed' => 'https://vtdigger.org/feed/', 'home' => 'https://vtdigger.org', 'geo' => 'us-ne', 'lang' => 'en', 'type' => 'digital', 'cat' => 'local', 'region' => 'Vermont'],
    ['name' => 'Bangor Daily News', 'feed' => 'https://www.bangordailynews.com/feed/', 'home' => 'https://www.bangordailynews.com', 'geo' => 'us-ne', 'lang' => 'en', 'type' => 'newspaper', 'cat' => 'local', 'region' => 'Maine'],

    // --- United States · New York ----------------------------------------------------
    ['name' => 'Gothamist', 'feed' => 'https://gothamist.com/feed', 'home' => 'https://gothamist.com', 'geo' => 'us-ny', 'lang' => 'en', 'type' => 'broadcaster', 'cat' => 'local'],
    ['name' => 'THE CITY', 'feed' => 'https://www.thecity.nyc/feed/', 'home' => 'https://www.thecity.nyc', 'geo' => 'us-ny', 'lang' => 'en', 'type' => 'digital', 'cat' => 'local'],
    ['name' => 'amNY', 'feed' => 'https://www.amny.com/feed/', 'home' => 'https://www.amny.com', 'geo' => 'us-ny', 'lang' => 'en', 'type' => 'newspaper', 'cat' => 'local'],
    ['name' => 'New York Post', 'feed' => 'https://nypost.com/feed/', 'home' => 'https://nypost.com', 'geo' => 'us-ny', 'lang' => 'en', 'type' => 'newspaper'],
    ['name' => 'Brooklyn Paper', 'feed' => 'https://www.brooklynpaper.com/feed/', 'home' => 'https://www.brooklynpaper.com', 'geo' => 'us-ny', 'lang' => 'en', 'type' => 'newspaper', 'cat' => 'local'],
    ['name' => 'New York Focus', 'feed' => 'https://nysfocus.com/feed', 'home' => 'https://nysfocus.com', 'geo' => 'us-ny', 'lang' => 'en', 'type' => 'digital', 'cat' => 'politics'],
    ['name' => 'City & State NY', 'feed' => 'https://www.cityandstateny.com/rss/all/', 'home' => 'https://www.cityandstateny.com', 'geo' => 'us-ny', 'lang' => 'en', 'type' => 'magazine', 'cat' => 'politics'],

    // --- Spain · National ------------------------------------------------------------
    ['name' => 'El País', 'feed' => 'https://feeds.elpais.com/mrss-s/pages/ep/site/elpais.com/portada', 'home' => 'https://elpais.com', 'geo' => 'es', 'lang' => 'es', 'type' => 'newspaper'],
    ['name' => 'El Mundo', 'feed' => 'https://e00-elmundo.uecdn.es/elmundo/rss/portada.xml', 'home' => 'https://www.elmundo.es', 'geo' => 'es', 'lang' => 'es', 'type' => 'newspaper'],
    ['name' => 'ABC', 'feed' => 'https://www.abc.es/rss/2.0/portada/', 'home' => 'https://www.abc.es', 'geo' => 'es', 'lang' => 'es', 'type' => 'newspaper'],
    ['name' => 'La Vanguardia', 'feed' => 'https://www.lavanguardia.com/rss/home.xml', 'home' => 'https://www.lavanguardia.com', 'geo' => 'es', 'lang' => 'es', 'type' => 'newspaper'],
    ['name' => '20minutos', 'feed' => 'https://www.20minutos.es/rss/', 'home' => 'https://www.20minutos.es', 'geo' => 'es', 'lang' => 'es', 'type' => 'newspaper'],
    ['name' => 'elDiario.es', 'feed' => 'https://www.eldiario.es/rss/', 'home' => 'https://www.eldiario.es', 'geo' => 'es', 'lang' => 'es', 'type' => 'digital'],
    ['name' => 'El Confidencial', 'feed' => 'https://rss.elconfidencial.com/espana/', 'home' => 'https://www.elconfidencial.com', 'geo' => 'es', 'lang' => 'es', 'type' => 'digital'],
    ['name' => 'El Español', 'feed' => 'https://www.elespanol.com/rss/', 'home' => 'https://www.elespanol.com', 'geo' => 'es', 'lang' => 'es', 'type' => 'digital'],
    ['name' => 'Europa Press', 'feed' => 'https://www.europapress.es/rss/rss.aspx', 'home' => 'https://www.europapress.es', 'geo' => 'es', 'lang' => 'es', 'type' => 'wire'],
    ['name' => 'Newtral', 'feed' => 'https://www.newtral.es/feed/', 'home' => 'https://www.newtral.es', 'geo' => 'es', 'lang' => 'es', 'type' => 'digital'],
    ['name' => 'Cinco Días', 'feed' => 'https://feeds.elpais.com/mrss-s/pages/ep/site/cincodias.elpais.com/portada', 'home' => 'https://cincodias.elpais.com', 'geo' => 'es', 'lang' => 'es', 'type' => 'newspaper', 'cat' => 'business'],
    ['name' => 'Xataka', 'feed' => 'https://www.xataka.com/feedburner.xml', 'home' => 'https://www.xataka.com', 'geo' => 'es', 'lang' => 'es', 'type' => 'digital', 'cat' => 'technology'],
    ['name' => 'Marca', 'feed' => 'https://e00-marca.uecdn.es/rss/portada.xml', 'home' => 'https://www.marca.com', 'geo' => 'es', 'lang' => 'es', 'type' => 'newspaper', 'cat' => 'sports'],
    ['name' => 'El País in English', 'feed' => 'https://feeds.elpais.com/mrss-s/pages/ep/site/english.elpais.com/portada', 'home' => 'https://english.elpais.com', 'geo' => 'es', 'lang' => 'en', 'type' => 'newspaper'],
    ['name' => 'The Local Spain', 'feed' => 'https://www.thelocal.es/feeds/rss.php', 'home' => 'https://www.thelocal.es', 'geo' => 'es', 'lang' => 'en', 'type' => 'digital'],
    ['name' => 'The Olive Press', 'feed' => 'https://www.theolivepress.es/feed/', 'home' => 'https://www.theolivepress.es', 'geo' => 'es', 'lang' => 'en', 'type' => 'newspaper'],

    // --- Spain · Valencian Community -------------------------------------------------
    // Prensa Ibérica titles (Levante-EMV, Información, Mediterráneo) block feed readers (HTTP 406).
    ['name' => 'Las Provincias', 'feed' => 'https://www.lasprovincias.es/rss/2.0/?section=comunitat', 'home' => 'https://www.lasprovincias.es', 'geo' => 'es-vc', 'lang' => 'es', 'type' => 'newspaper', 'cat' => 'local'],
    ['name' => 'Valencia Plaza', 'feed' => 'https://valenciaplaza.com/rss', 'home' => 'https://valenciaplaza.com', 'geo' => 'es-vc', 'lang' => 'es', 'type' => 'digital', 'cat' => 'local'],
    ['name' => 'elDiario.es Comunitat Valenciana', 'feed' => 'https://www.eldiario.es/rss/comunitat-valenciana/', 'home' => 'https://www.eldiario.es/comunitat-valenciana/', 'geo' => 'es-vc', 'lang' => 'es', 'type' => 'digital', 'cat' => 'local'],
    ['name' => 'El País Comunitat Valenciana', 'feed' => 'https://feeds.elpais.com/mrss-s/list/ep/site/elpais.com/section/espana/subsection/comunidad-valenciana', 'home' => 'https://elpais.com/espana/comunidad-valenciana/', 'geo' => 'es-vc', 'lang' => 'es', 'type' => 'newspaper', 'cat' => 'local'],

    // --- Spain · Alicante ------------------------------------------------------------
    ['name' => 'Alicante Plaza', 'feed' => 'https://alicanteplaza.es/rss', 'home' => 'https://alicanteplaza.es', 'geo' => 'es-alc', 'lang' => 'es', 'type' => 'digital', 'cat' => 'local'],
    ['name' => 'TodoAlicante', 'feed' => 'https://www.todoalicante.es/rss/2.0/?section=', 'home' => 'https://www.todoalicante.es', 'geo' => 'es-alc', 'lang' => 'es', 'type' => 'digital', 'cat' => 'local'],
    ['name' => 'El Español Alicante', 'feed' => 'https://www.elespanol.com/rss/alicante/', 'home' => 'https://www.elespanol.com/alicante/', 'geo' => 'es-alc', 'lang' => 'es', 'type' => 'digital', 'cat' => 'local'],
    ['name' => 'The Leader', 'feed' => 'https://theleader.info/feed/', 'home' => 'https://theleader.info', 'geo' => 'es-alc', 'lang' => 'en', 'type' => 'newspaper', 'cat' => 'local', 'region' => 'Costa Blanca'],

    // --- European Union --------------------------------------------------------------
    // Politico Europe, Euractiv and EU Reporter block feed readers (HTTP 403).
    ['name' => 'EUobserver', 'feed' => 'https://euobserver.com/feed', 'home' => 'https://euobserver.com', 'geo' => 'eu', 'lang' => 'en', 'type' => 'digital', 'cat' => 'politics'],
    ['name' => 'Euronews My Europe', 'feed' => 'https://www.euronews.com/rss?format=mrss&level=vertical&name=my-europe', 'home' => 'https://www.euronews.com/my-europe', 'geo' => 'eu', 'lang' => 'en', 'type' => 'broadcaster'],
    ['name' => 'Euronews', 'feed' => 'https://www.euronews.com/rss?format=mrss&level=theme&name=news', 'home' => 'https://www.euronews.com', 'geo' => 'eu', 'lang' => 'en', 'type' => 'broadcaster'],
    ['name' => 'Euronews Español', 'feed' => 'https://es.euronews.com/rss?format=mrss&level=theme&name=news', 'home' => 'https://es.euronews.com', 'geo' => 'eu', 'lang' => 'es', 'type' => 'broadcaster'],
    ['name' => 'Eunews', 'feed' => 'https://www.eunews.it/en/feed/', 'home' => 'https://www.eunews.it/en/', 'geo' => 'eu', 'lang' => 'en', 'type' => 'digital', 'cat' => 'politics'],
    ['name' => 'Council of the EU', 'feed' => 'https://www.consilium.europa.eu/en/rss/pressreleases.ashx', 'home' => 'https://www.consilium.europa.eu/en/', 'geo' => 'eu', 'lang' => 'en', 'type' => 'institution', 'cat' => 'politics'],

    // --- United Kingdom --------------------------------------------------------------
    ['name' => 'BBC News', 'feed' => 'https://feeds.bbci.co.uk/news/rss.xml', 'home' => 'https://www.bbc.co.uk/news', 'geo' => 'uk', 'lang' => 'en', 'type' => 'public'],
    ['name' => 'The Guardian UK', 'feed' => 'https://www.theguardian.com/uk-news/rss', 'home' => 'https://www.theguardian.com/uk', 'geo' => 'uk', 'lang' => 'en', 'type' => 'newspaper'],
    ['name' => 'The Independent', 'feed' => 'https://www.independent.co.uk/news/uk/rss', 'home' => 'https://www.independent.co.uk', 'geo' => 'uk', 'lang' => 'en', 'type' => 'digital'],
    ['name' => 'Sky News', 'feed' => 'https://feeds.skynews.com/feeds/rss/home.xml', 'home' => 'https://news.sky.com', 'geo' => 'uk', 'lang' => 'en', 'type' => 'broadcaster'],

    // --- Europe · National (English-language) ---------------------------------------
    ['name' => 'DW', 'feed' => 'https://rss.dw.com/rdf/rss-en-all', 'home' => 'https://www.dw.com/en', 'geo' => 'europe', 'lang' => 'en', 'type' => 'public', 'country' => 'DE'],
    ['name' => 'DW Español', 'feed' => 'https://rss.dw.com/rdf/rss-sp-all', 'home' => 'https://www.dw.com/es', 'geo' => 'europe', 'lang' => 'es', 'type' => 'public', 'country' => 'DE'],
    ['name' => 'France 24', 'feed' => 'https://www.france24.com/en/rss', 'home' => 'https://www.france24.com/en/', 'geo' => 'europe', 'lang' => 'en', 'type' => 'public', 'country' => 'FR'],
    ['name' => 'France 24 Español', 'feed' => 'https://www.france24.com/es/rss', 'home' => 'https://www.france24.com/es/', 'geo' => 'europe', 'lang' => 'es', 'type' => 'public', 'country' => 'FR'],
    ['name' => 'Le Monde in English', 'feed' => 'https://www.lemonde.fr/en/rss/une.xml', 'home' => 'https://www.lemonde.fr/en/', 'geo' => 'europe', 'lang' => 'en', 'type' => 'newspaper', 'country' => 'FR'],
    ['name' => 'RFI English', 'feed' => 'https://www.rfi.fr/en/rss', 'home' => 'https://www.rfi.fr/en/', 'geo' => 'europe', 'lang' => 'en', 'type' => 'public', 'country' => 'FR'],
    ['name' => 'RFI Español', 'feed' => 'https://www.rfi.fr/es/rss', 'home' => 'https://www.rfi.fr/es/', 'geo' => 'europe', 'lang' => 'es', 'type' => 'public', 'country' => 'FR'],
    ['name' => 'ANSA English', 'feed' => 'https://www.ansa.it/english/english_rss.xml', 'home' => 'https://www.ansa.it/english/', 'geo' => 'europe', 'lang' => 'en', 'type' => 'wire', 'country' => 'IT'],
    ['name' => 'Yle News', 'feed' => 'https://yle.fi/rss/news', 'home' => 'https://yle.fi/news', 'geo' => 'europe', 'lang' => 'en', 'type' => 'public', 'country' => 'FI'],
    ['name' => 'RTÉ News', 'feed' => 'https://www.rte.ie/feeds/rss/?index=/news/', 'home' => 'https://www.rte.ie/news/', 'geo' => 'europe', 'lang' => 'en', 'type' => 'public', 'country' => 'IE'],
    ['name' => 'NL Times', 'feed' => 'https://nltimes.nl/rss', 'home' => 'https://nltimes.nl', 'geo' => 'europe', 'lang' => 'en', 'type' => 'digital', 'country' => 'NL'],
    ['name' => 'The Local Germany', 'feed' => 'https://www.thelocal.de/feeds/rss.php', 'home' => 'https://www.thelocal.de', 'geo' => 'europe', 'lang' => 'en', 'type' => 'digital', 'country' => 'DE'],
    ['name' => 'The Local France', 'feed' => 'https://www.thelocal.fr/feeds/rss.php', 'home' => 'https://www.thelocal.fr', 'geo' => 'europe', 'lang' => 'en', 'type' => 'digital', 'country' => 'FR'],
    ['name' => 'The Local Italy', 'feed' => 'https://www.thelocal.it/feeds/rss.php', 'home' => 'https://www.thelocal.it', 'geo' => 'europe', 'lang' => 'en', 'type' => 'digital', 'country' => 'IT'],
    ['name' => 'Notes from Poland', 'feed' => 'https://notesfrompoland.com/feed/', 'home' => 'https://notesfrompoland.com', 'geo' => 'europe', 'lang' => 'en', 'type' => 'digital', 'country' => 'PL'],
    ['name' => 'Balkan Insight', 'feed' => 'https://balkaninsight.com/feed/', 'home' => 'https://balkaninsight.com', 'geo' => 'europe', 'lang' => 'en', 'type' => 'digital', 'country' => 'RS'],
    ['name' => 'Kyiv Independent', 'feed' => 'https://kyivindependent.com/news-archive/rss/', 'home' => 'https://kyivindependent.com', 'geo' => 'europe', 'lang' => 'en', 'type' => 'digital', 'country' => 'UA'],
    ['name' => 'Portugal Resident', 'feed' => 'https://www.portugalresident.com/feed/', 'home' => 'https://www.portugalresident.com', 'geo' => 'europe', 'lang' => 'en', 'type' => 'newspaper', 'country' => 'PT'],

    // --- International ---------------------------------------------------------------
    ['name' => 'BBC World', 'feed' => 'https://feeds.bbci.co.uk/news/world/rss.xml', 'home' => 'https://www.bbc.co.uk/news/world', 'geo' => 'intl', 'lang' => 'en', 'type' => 'public', 'cat' => 'world', 'country' => 'GB'],
    ['name' => 'BBC Mundo', 'feed' => 'https://feeds.bbci.co.uk/mundo/rss.xml', 'home' => 'https://www.bbc.com/mundo', 'geo' => 'intl', 'lang' => 'es', 'type' => 'public', 'cat' => 'world', 'country' => 'GB'],
    ['name' => 'Al Jazeera', 'feed' => 'https://www.aljazeera.com/xml/rss/all.xml', 'home' => 'https://www.aljazeera.com', 'geo' => 'intl', 'lang' => 'en', 'type' => 'broadcaster', 'cat' => 'world', 'country' => 'QA'],
    ['name' => 'The Guardian World', 'feed' => 'https://www.theguardian.com/world/rss', 'home' => 'https://www.theguardian.com/world', 'geo' => 'intl', 'lang' => 'en', 'type' => 'newspaper', 'cat' => 'world', 'country' => 'GB'],
    ['name' => 'UN News', 'feed' => 'https://news.un.org/feed/subscribe/en/news/all/rss.xml', 'home' => 'https://news.un.org/en/', 'geo' => 'intl', 'lang' => 'en', 'type' => 'institution', 'cat' => 'world'],
    ['name' => 'Noticias ONU', 'feed' => 'https://news.un.org/feed/subscribe/es/news/all/rss.xml', 'home' => 'https://news.un.org/es/', 'geo' => 'intl', 'lang' => 'es', 'type' => 'institution', 'cat' => 'world'],
    ['name' => 'The Conversation', 'feed' => 'https://theconversation.com/global/articles.atom', 'home' => 'https://theconversation.com/global', 'geo' => 'intl', 'lang' => 'en', 'type' => 'digital'],
    ['name' => 'Rest of World', 'feed' => 'https://restofworld.org/feed/latest/', 'home' => 'https://restofworld.org', 'geo' => 'intl', 'lang' => 'en', 'type' => 'digital', 'cat' => 'technology'],
    ['name' => 'Infobae', 'feed' => 'https://www.infobae.com/arc/outboundfeeds/rss/?outputType=xml', 'home' => 'https://www.infobae.com', 'geo' => 'intl', 'lang' => 'es', 'type' => 'digital', 'country' => 'AR'],
    ['name' => 'El País América', 'feed' => 'https://feeds.elpais.com/mrss-s/pages/ep/site/elpais.com/section/america/portada', 'home' => 'https://elpais.com/america/', 'geo' => 'intl', 'lang' => 'es', 'type' => 'newspaper', 'cat' => 'world'],
    ['name' => 'Nature News', 'feed' => 'https://www.nature.com/nature.rss', 'home' => 'https://www.nature.com', 'geo' => 'intl', 'lang' => 'en', 'type' => 'magazine', 'cat' => 'science'],
];
