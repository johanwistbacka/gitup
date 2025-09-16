        // Toggle plugin/theme details row
        document.addEventListener('DOMContentLoaded', function() {
          document.querySelectorAll('.rgplugins-toggle-details').forEach(function(btn){
            btn.addEventListener('click',function(){
              var details = btn.closest('tr').nextElementSibling;
              if(details && details.classList.contains('rgplugins-details')){
                var isOpen = details.classList.contains('open');
                details.classList.toggle('open');
                btn.setAttribute('aria-expanded', !isOpen);
              }
            });
          });
        });
