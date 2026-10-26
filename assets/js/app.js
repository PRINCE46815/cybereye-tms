/* TMS — app.js
   Small, dependency-free UI helpers. */

function toggleNav(open){
  var sb = document.getElementById('sidebar');
  var bd = document.getElementById('backdrop');
  if(!sb) return;
  if(open){ sb.classList.add('open'); bd.classList.add('show'); }
  else    { sb.classList.remove('open'); bd.classList.remove('show'); }
}

/* Tabs: a .tabs container with button[data-tab] toggles matching .tab-pane */
document.addEventListener('DOMContentLoaded', function(){
  document.querySelectorAll('.tabs').forEach(function(group){
    var btns = group.querySelectorAll('button[data-tab]');
    btns.forEach(function(btn){
      btn.addEventListener('click', function(){
        var target = btn.getAttribute('data-tab');
        btns.forEach(function(b){ b.classList.toggle('active', b === btn); });
        document.querySelectorAll('.tab-pane').forEach(function(p){
          p.classList.toggle('active', p.id === target);
        });
      });
    });
  });
});
document.addEventListener('DOMContentLoaded', function(){
  var flashes = document.querySelectorAll('.flash');
  flashes.forEach(function(f){
    setTimeout(function(){
      f.style.transition = 'opacity .4s';
      f.style.opacity = '0';
      setTimeout(function(){ f.remove(); }, 400);
    }, 6000);
  });
});
