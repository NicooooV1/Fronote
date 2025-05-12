// Module de gestion des fichiers iCal
const ICalManager = {
    // Analyser un fichier iCal
    parseICalFile: function(content) {
        const events = [];
        const lines = content.split(/\r\n|\n|\r/);
        let currentEvent = null;
        
        for (let i = 0; i < lines.length; i++) {
            const line = lines[i];
            
            if (line === 'BEGIN:VEVENT') {
                currentEvent = {};
            } else if (line === 'END:VEVENT' && currentEvent) {
                events.push(currentEvent);
                currentEvent = null;
            } else if (currentEvent) {
                // Lignes suivies
                if (line.startsWith(' ') && i > 0) {
                    const previousProp = lines[i - 1].split(':')[0];
                    currentEvent[previousProp] += line.substring(1);
                    continue;
                }
                
                const parts = line.split(':');
                if (parts.length < 2) continue;
                
                const propParts = parts[0].split(';');
                const prop = propParts[0];
                const value = parts.slice(1).join(':');
                
                switch (prop) {
                    case 'SUMMARY':
                        currentEvent.title = value;
                        break;
                    case 'DESCRIPTION':
                        currentEvent.description = value;
                        break;
                    case 'LOCATION':
                        currentEvent.location = value;
                        break;
                    case 'DTSTART':
                        currentEvent.start = this.parseICalDate(propParts, value);
                        break;
                    case 'DTEND':
                        currentEvent.end = this.parseICalDate(propParts, value);
                        break;
                    default:
                        currentEvent[prop] = value;
                }
            }
        }
        
        return events;
    },
    
    // Analyser une date iCal
    parseICalDate: function(propParts, value) {
        let tzid = null;
        
        // Chercher un fuseau horaire
        for (const part of propParts) {
            if (part.startsWith('TZID=')) {
                tzid = part.substring(5);
                break;
            }
        }
        
        // Formater la date
        const year = value.substring(0, 4);
        const month = value.substring(4, 6);
        const day = value.substring(6, 8);
        
        let formatted = `${year}-${month}-${day}`;
        
        // Ajouter l'heure si présente
        if (value.length > 8) {
            const hour = value.substring(9, 11);
            const minute = value.substring(11, 13);
            const second = value.substring(13, 15);
            
            formatted += `T${hour}:${minute}:${second}`;
            
            // Ajouter le fuseau horaire
            if (tzid) {
                formatted += tzid;
            } else if (value.charAt(15) === 'Z') {
                formatted += 'Z';
            }
        }
        
        return formatted;
    },
    
    // Générer un fichier iCal à partir d'événements
    generateICalFile: function(events) {
        let ical = "BEGIN:VCALENDAR\r\n";
        ical += "VERSION:2.0\r\n";
        ical += "PRODID:-//MonPronoteWeb//AGENDA//FR\r\n";
        
        for (const event of events) {
            ical += "BEGIN:VEVENT\r\n";
            ical += `UID:${this.generateUUID()}\r\n`;
            ical += `DTSTAMP:${this.formatICalDate(new Date())}\r\n`;
            
            // Dates de début et fin
            const start = new Date(event.start);
            const end = new Date(event.end);
            
            ical += `DTSTART;TZID=Europe/Paris:${this.formatICalDate(start)}\r\n`;
            ical += `DTEND;TZID=Europe/Paris:${this.formatICalDate(end)}\r\n`;
            
            // Titre et autres propriétés
            ical += `SUMMARY:${event.title}\r\n`;
            
            if (event.description) {
                ical += `DESCRIPTION:${event.description}\r\n`;
            }
            
            if (event.location) {
                ical += `LOCATION:${event.location}\r\n`;
            }
            
            ical += "END:VEVENT\r\n";
        }
        
        ical += "END:VCALENDAR";
        
        return ical;
    },
    
    // Formater une date pour iCal
    formatICalDate: function(date) {
        const pad = num => num.toString().padStart(2, '0');
        
        const year = date.getFullYear();
        const month = pad(date.getMonth() + 1);
        const day = pad(date.getDate());
        const hour = pad(date.getHours());
        const minute = pad(date.getMinutes());
        const second = pad(date.getSeconds());
        
        return `${year}${month}${day}T${hour}${minute}${second}`;
    },
    
    // Générer un UUID (version simple)
    generateUUID: function() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
            const r = Math.random() * 16 | 0;
            const v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }
};

// Export pour utilisation dans d'autres modules
window.ICalManager = ICalManager;