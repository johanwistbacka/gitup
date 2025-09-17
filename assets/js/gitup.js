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

        // Version select change listener
        document.addEventListener('DOMContentLoaded', function() {
          function parseVersion(v){
            if(!v) return {parts:[0], pre:false};
            var s = v.toString().trim();
            var pre = s.indexOf('-') !== -1; // treat any suffix (e.g. -beta) as prerelease
            var base = s.split('-')[0];
            var parts = base.split('.').map(function(p){
              var m = p.match(/^\d+/); // take leading digits only
              return m ? parseInt(m[0], 10) : 0;
            });
            return {parts: parts, pre: pre};
          }

          function compareVersions(a, b) {
            var va = parseVersion(a);
            var vb = parseVersion(b);
            var len = Math.max(va.parts.length, vb.parts.length);
            for (var i = 0; i < len; i++) {
              var na = va.parts[i] || 0;
              var nb = vb.parts[i] || 0;
              if (na > nb) return 1;
              if (na < nb) return -1;
            }
            // Numeric parts equal; prerelease is considered lower than final release
            if (va.pre && !vb.pre) return -1;
            if (!va.pre && vb.pre) return 1;
            return 0;
          }

          document.querySelectorAll('.rg-version-select').forEach(function(select){
            select.addEventListener('change', function() {
              var selectedVersion = select.value;
              // console.log('Selected version:', selectedVersion);
              var row = select.closest('tr');
              if (!row) return;

              var currentVersion = row.dataset.currentversion || row.dataset.currentVersion;
              // console.log('currentVersion:', currentVersion);
              var cmp = compareVersions(selectedVersion, currentVersion);
              if (cmp > 0) {
                console.log('Upgrade: from', currentVersion, 'to', selectedVersion);
              } else if (cmp < 0) {
                console.log('Downgrade: from', currentVersion, 'to', selectedVersion);
              } else {
                console.log('Re-install: version', currentVersion);
              }
              var installBtn = row.querySelector('input[type="submit"]');
              if (!installBtn || !currentVersion) return;

              
              var label = '';
              var className = '';
              var classRow = '';

              if (cmp > 0) {
                label = 'Update';
                className = 'button update';
                classRow = 'update';
              } else if (cmp < 0) {
                label = 'Downgrade';
                className = 'button downgrade';
                classRow = 'downgrade';
              } else {
                label = 'Re-install';
                className = 'button reinstall';
                classRow = 'reinstall';
              }

              installBtn.value = label;
              installBtn.className = className;
              row.classList.remove('update');
              row.classList.remove('downgrade');
              row.classList.remove('reinstall');
              if (classRow) {
                row.classList.add(classRow);
              }
            });
          });
        });
