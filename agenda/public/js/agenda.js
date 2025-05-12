// Chargement des cours et affichage simple
async function loadEvents(start, end) {
  const res = await fetch(`/controllers/AgendaController.php?action=getEvents&start=${start}&end=${end}`);
  return res.json();
}

function renderCalendar(events) {
  const cal = document.getElementById('calendar');
  cal.innerHTML = '';

  // En-têtes jours
  cal.appendChild(createCell('')); // coin vide
  for (let d = 1; d <= 7; d++) {
    const cell = createCell(['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'][d-1]);
    cell.classList.add('hour');
    cal.appendChild(cell);
  }
  // Heures et cases
  for (let h = 8; h <= 18; h++) {
    cal.appendChild(createCell(h + 'h'));
    for (let d = 1; d <= 7; d++) {
      const slot = createCell('');
      slot.id = `cell-${d}-${h}`;
      cal.appendChild(slot);
    }
  }

  // Placer les événements
  events.forEach(evt => {
    const date = new Date(evt.start);
    const day  = date.getDay(); // 1=Dim, 0=Dim
    const hour = date.getHours();
    const cell = document.getElementById(`cell-${day || 7}-${hour}`);
    if (cell) {
      const div = document.createElement('div');
      div.classList.add('event');
      div.style.background = evt.color;
      div.textContent = evt.title;
      cell.appendChild(div);
    }
  });
}

function createCell(content) {
  const div = document.createElement('div');
  div.textContent = content;
  return div;
}

// Initialisation
(async () => {
  const today = new Date();
  const monday = new Date(today.setDate(today.getDate() - (today.getDay() + 6) % 7));
  const start = monday.toISOString().split('T')[0];
  const end   = new Date(monday.getTime() + 6*24*3600*1000).toISOString().split('T')[0];

  const events = await loadEvents(start, end);
  renderCalendar(events);

  document.getElementById('export-ics').addEventListener('click', () => {
    window.location.href = `/export_ics.php?start=${start}&end=${end}`;
  });
})();