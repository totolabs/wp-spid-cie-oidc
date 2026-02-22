# SPID SAML Step 2 — Battery di test GO/NO-GO

Questa checklist serve a validare in modo ripetibile lo Step 2 del modulo SPID SAML:
- parsing/validazione base `SAMLResponse`
- login WordPress
- provisioning utente
- logout locale via SLS

## 0) Pre-flight (obbligatorio)

1. Aggiorna plugin e svuota cache (plugin cache + CDN + opcode se presente).
2. Vai in **Impostazioni → SPID & CIE Login → tab F. SPID SAML**.
3. Verifica:
   - `Abilita SPID SAML = ON`
   - `SPID SAML EntityID` coerente con ambiente
   - `Provisioning automatico utenti` secondo scenario test
   - `Ruolo default nuovi utenti` impostato
   - `Debug SPID SAML = ON` solo in collaudo
4. Re-salva i permalink (una volta) se gli endpoint non rispondono.

## 1) Smoke test endpoint (curl)

> Sostituisci `https://example.gov.it` con il dominio reale.

### 1.1 Metadata disponibile
```bash
curl -i https://example.gov.it/spid/saml/metadata
```
**Atteso**
- HTTP `200`
- `Content-Type: application/samlmetadata+xml`
- XML con `EntityDescriptor`, `AssertionConsumerService`, `SingleLogoutService`.

### 1.2 ACS metodo errato
```bash
curl -i https://example.gov.it/spid/saml/acs
```
**Atteso**
- HTTP `405`
- Header `Allow: POST`

### 1.3 SLS metodi consentiti
```bash
curl -i https://example.gov.it/spid/saml/sls
```
**Atteso**
- HTTP `200` (GET consentito)

## 2) Test funzionali principali (IdP/SPID Validator)

## 2.1 Login SPID SAML — utente esistente
Precondizioni:
- Esiste utente WordPress già mappato (sub/fiscal code).

Passi:
1. Avvia login da IdP verso ACS del plugin.
2. Completa autenticazione.

Atteso:
- redirect finale su pagina target (o home)
- sessione utente WordPress attiva
- nessun errore `spid_cie_error`

## 2.2 Login SPID SAML — provisioning ON
Precondizioni:
- `Provisioning automatico utenti = ON`
- identità non presente in WP.

Atteso:
- creazione nuovo utente
- ruolo uguale a `spid_saml_default_role`
- login completato

## 2.3 Login SPID SAML — provisioning OFF
Precondizioni:
- `Provisioning automatico utenti = OFF`
- identità non presente.

Atteso:
- login rifiutato
- redirect a login con `spid_cie_error` valorizzato
- nessun nuovo utente creato

## 3) Test negativi ACS (robustezza)

## 3.1 `SAMLResponse` mancante
Invia POST senza `SAMLResponse`.

Atteso:
- redirect a login con errore (`saml_missing_response`)
- niente fatal error

## 3.2 `SAMLResponse` non-base64
Invia POST con stringa non valida.

Atteso:
- errore `saml_invalid_payload`

## 3.3 XML invalido
Invia base64 di XML rotto.

Atteso:
- errore `saml_invalid_xml`

## 3.4 Status non-success
Response con `StatusCode != Success`.

Atteso:
- errore `saml_status_not_success`

## 3.5 Assertion scaduta/non valida temporalmente
- `NotBefore` troppo nel futuro o `NotOnOrAfter` passato.

Atteso:
- errore `saml_not_yet_valid` oppure `saml_expired`

## 4) Test SLS (logout locale)

Passi:
1. Esegui login.
2. Chiama endpoint SLS (`GET /spid/saml/sls`).
3. Apri una pagina protetta wp-admin.

Atteso:
- utente disconnesso localmente
- richiesta autenticazione WordPress

## 5) Test sicurezza minima

1. Verifica che `RelayState` esterno non provochi open redirect.
2. Verifica assenza log sensibili (no dump completo SAMLResponse).
3. Con debug ON, verifica solo header/log diagnostici minimi.
4. Con debug OFF, assenza header diagnostici extra.

## 6) Criteri GO/NO-GO

### GO
- Tutti i test di sezione 1, 2 e 4 passano.
- In sezione 3 gli errori sono gestiti senza fatal e con redirect controllato.
- Nessun open redirect e nessuna perdita dati sensibili nei log.

### NO-GO
- Qualsiasi fatal error / schermata bianca.
- Provisioning errato (ruolo sbagliato o creazione non voluta).
- Redirect verso host esterni da RelayState.
- Endpoint metadata/ACS/SLS non coerenti con expected HTTP behavior.

## 7) Evidenze da allegare alla PR di collaudo

- output comandi `curl -i` (sezione 1)
- screenshot tab F. SPID SAML con impostazioni usate
- screenshot/login success e failure case
- estratto log applicativo con correlation id (senza payload sensibili)

