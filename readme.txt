=== SPID & CIE OIDC Login per WordPress ===
Contributors: totolabs
Tags: spid, cie, oidc, login, pnrr, pa, openid connect, italia
Requires at least: 6.0
Tested up to: 6.7
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Abilita l'accesso conforme PNRR 1.4.4 con SPID e CIE (Protocollo OIDC). Semplice, Sicuro, Open Source.

== Description ==

Questo plugin permette di integrare il login con **SPID** (Sistema Pubblico di Identità Digitale) e **CIE** (Carta d'Identità Elettronica) utilizzando il nuovo standard **OpenID Connect (OIDC) Federation 1.0**, come richiesto dalle linee guida AgID e dalla misura PNRR 1.4.4.

Sviluppato pensando alle esigenze delle Pubbliche Amministrazioni italiane (Comuni, Ordini Professionali, Enti Locali).

**Funzionalità Principali:**
* **Onboarding Semplificato:** Generazione automatica delle chiavi crittografiche (JWKS) e dell'Entity Statement (JWS) necessario per la registrazione sui portali AgID (Registry) e Federazione CIE.
* **SPID Smart Button:** Pulsante di login conforme alle linee guida UX di AgID, con selezione dell'Identity Provider (Poste, Aruba, Sielte, ecc.) tramite finestra modale integrata.
* **CIE Button:** Pulsante ufficiale "Entra con CIE".
* **Disclaimer Manutenzione:** Possibilità di attivare un banner di avviso personalizzabile nella pagina di login (utile durante le fasi di transizione o disservizio dei portali ministeriali).
* **Modalità Ibrida:** Progettato per coesistere con plugin SPID SAML preesistenti durante la fase di migrazione tecnologica.
* **Ambiente di Test:** Supporto integrato per il Validator AgID (OIDC).

**Requisiti di Sistema:**
* PHP 7.4 o superiore
* Estensioni PHP richieste: `gmp`, `mbstring`, `openssl`, `curl`, `json`.

== Installation ==

1. Carica la cartella del plugin nella directory `/wp-content/plugins/`.
2. Attiva il plugin tramite il menu 'Plugin' in WordPress.
3. Vai su **Impostazioni > SPID & CIE Login**.
4. Compila i dati dell'Ente (IPA, Codice Fiscale, Contatti).
5. Clicca su "Genera Chiavi".
6. Copia l'**Entity Statement URI** generato e comunicalo ad AgID (Registry) e al Ministero dell'Interno (Federazione CIE) per l'onboarding tecnico.
7. Una volta attivati i servizi sui portali istituzionali, abilita i pulsanti di login nelle impostazioni del plugin.

== Changelog ==

= 1.0.0 =
* Rilascio iniziale pubblico.
* Supporto completo OIDC Federation 1.0.
* Generazione chiavi RSA e firma JWS RS256.
* Smart Button SPID con selezione IdP.
* Gestione Disclaimer avvisi.