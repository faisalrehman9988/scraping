<?php
// Output the initial HTML structure and the text container
echo '<h1 id="text-container">welcome</h1>';
?>

<script>
// Wait for 5 seconds (5000 milliseconds)
setTimeout(function() {
    // 1. Get the HTML element containing the word
    var container = document.getElementById('text-container');
    
    // 2. Get the current text ("welcome")
    var currentText = container.innerText;
    
    // 3. Replace all instances of the letter 'e' with nothing
    // The /e/g tells JavaScript to replace 'e' globally (all of them)
    var updatedText = currentText.replace(/e/g, '');
    
    // 4. Push the new text ("wlcom") back into the webpage
    container.innerText = updatedText;
}, 5000); 
</script>