// Gestion du menu mobile (responsive)
document.addEventListener('DOMContentLoaded', function() {
    // Toggle du menu mobile
    var toggleButton = document.querySelector('.toggle-menu');
    if (toggleButton) {
        toggleButton.addEventListener('click', function() {
            document.querySelector('.main-nav').classList.toggle('open');
        });
    }
    
    // Toggle de la sidebar sur mobile
    var toggleSidebar = document.querySelector('.toggle-sidebar');
    if (toggleSidebar) {
        toggleSidebar.addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('open');
        });
    }
    
    // Fermer les alertes
    var alertCloseButtons = document.querySelectorAll('.alert .close');
    alertCloseButtons.forEach(function(button) {
        button.addEventListener('click', function() {
            this.parentElement.style.display = 'none';
        });
    });
    
    // Initialiser les tooltips
    var tooltips = document.querySelectorAll('[data-tooltip]');
    tooltips.forEach(function(tooltip) {
        tooltip.addEventListener('mouseover', function() {
            var tooltipText = this.getAttribute('data-tooltip');
            var tooltipEl = document.createElement('div');
            tooltipEl.classList.add('tooltip');
            tooltipEl.textContent = tooltipText;
            document.body.appendChild(tooltipEl);
            
            var rect = this.getBoundingClientRect();
            tooltipEl.style.top = (rect.top - tooltipEl.offsetHeight - 10) + 'px';
            tooltipEl.style.left = (rect.left + (rect.width / 2) - (tooltipEl.offsetWidth / 2)) + 'px';
            tooltipEl.style.opacity = '1';
            
            this.addEventListener('mouseout', function() {
                tooltipEl.parentNode.removeChild(tooltipEl);
            });
        });
    });
});