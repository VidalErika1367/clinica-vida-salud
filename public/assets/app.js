document.querySelectorAll('[data-password-target]').forEach(button => {
    button.addEventListener('click', () => {
        const field = document.getElementById(button.dataset.passwordTarget);
        const visible = field.type === 'password';
        field.type = visible ? 'text' : 'password';
        button.textContent = visible ? 'Ocultar' : 'Ver';
        button.setAttribute('aria-label', visible ? 'Ocultar contraseña' : 'Mostrar contraseña');
    });
});
const roleSelect = document.getElementById('rol');
const patientFields = document.getElementById('patient-fields');
if (roleSelect && patientFields) {
    const update = () => {
        const isPatient = roleSelect.selectedOptions[0]?.dataset.role === 'paciente';
        patientFields.hidden = !isPatient;
        patientFields.querySelectorAll('input').forEach(field => { field.required = isPatient; });
    };
    roleSelect.addEventListener('change', update); update();
}
const specialtySelect = document.getElementById('availability-specialty');
if (specialtySelect) {
    specialtySelect.addEventListener('change', () => {
        const doctorSelect = document.getElementById('availability-doctor');
        if (doctorSelect) doctorSelect.value = '';
        document.getElementById('availability-search')?.requestSubmit();
    });
}
