<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
    <link href="assets/survey-core.fontless.min.css" type="text/css" rel="stylesheet">
    <script type="text/javascript" src="assets/survey.core.min.js"></script>
    <script type="text/javascript" src="assets/survey-js-ui.min.js"></script>
    <!-- 👇 Eigene Open Sans – lokale Fonts, DSGVO-konform -->
    <style>
      @font-face {
        font-family: 'Open Sans';
        font-weight: 400;
        font-style: normal;
        src: url('assets/fonts/opensans/open-sans-v44-latin-regular.woff2') format('woff2');
      }

      @font-face {
        font-family: 'Open Sans';
        font-weight: 700;
        font-style: normal;
        src: url('assets/fonts/opensans/open-sans-v44-latin-700.woff2') format('woff2');
      }

      /* Alle surveyJS Elemente bekommen unsere Font */
      :root {
        --font-family: 'Open Sans', sans-serif !important;
      }
    </style>
</head>
<body>
<div id="surveyContainer" style="margin: auto auto; width: 80%"></div>
    <?php
      // Lade die Formular-Konfiguration in Abhängigkeit vom Parameter "form" 
      require_once 'config/config.php';
      $form = $_REQUEST['form'] ?? 'default';
      if (!isset($configs[$form])) {
          echo ("Unbekanntes Formular");
          exit;    
      }

      $formConfig = $configs[$form];
      $formFile  = 'surveys/' . $formConfig['form'];
      $themeFile = 'surveys/' . $formConfig['theme'];
    ?>
  <script>

    
    // CSRF-Token abrufen
    let csrfToken = "";
    fetch("csrf_token.php")
      .then(r => r.json())
      .then(d => csrfToken = d.token);

    const surveyJson = <?php echo file_get_contents($formFile); ?>;
    
    const survey = new Survey.Model(surveyJson);


    const theme = <?php 
      $theme=file_get_contents($themeFile);
      echo ($theme?$theme:'""'); ?>;
    survey.applyTheme( theme );


    survey.onComplete.add(async function (sender) {
          const formData = new FormData();
          const data = sender.data;
          
          Object.keys(data)
            .filter(k => k.startsWith("consent_")) // Felder, die mit "consent_" anfangen, werden nicht abgespeichert.
            .forEach(k => delete data[k]);
          
          // Survey-Daten als JSON-String
          formData.append("survey_data", JSON.stringify(data));
          formData.append("csrf_token", csrfToken);

          // Falls Datei ausgewählt wurde, hänge sie an
          /*const fileQuestion = sender.getQuestionByName("anhang");
          if (fileQuestion && fileQuestion.value && fileQuestion.value.length > 0) {
            const file = fileQuestion.value[0];
            formData.append("anhang", file);
          }
          */
        for (const [key, val] of formData.entries()) console.log(key, val);

              let result = null;
              try {
                const response = await fetch("save.php?form=" + "<?php echo $form; ?>", {
                  method: "POST",
                  body: formData
                });
                result = await response.json();console.log(result);
              } catch (err) {
                console.error(err);
                alert("Fehler beim Senden der Anmeldung. Wir bitten um Entschuldigung.");
              }
              if (result && result.status != "ok") {        
                const completed = document.querySelector('.sd-completedpage');
                const message = "Fehler beim Senden der Anmeldung. Wir bitten um Entschuldigung.";
                if (completed) {          
                  completed.innerHTML = "Fehler beim Verarbeiten der Daten:"+message+"<br />Details:<pre>"+result.message+"</pre>";
                } else {
                  alert(message);
                }
              }
            });    

            survey.render(document.getElementById("surveyContainer"));
  </script>

  
</body>
</html>
