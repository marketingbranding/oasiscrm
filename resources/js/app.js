import Alpine from 'alpinejs';
import Sortable from 'sortablejs';
import './crm-datepicker';
import './crm-monthpicker';
import './crm-select';
import './crm-bulk';
import registerPresence from './presence';

window.Alpine = Alpine;
window.Sortable = Sortable;

registerPresence(Alpine);

Alpine.start();
