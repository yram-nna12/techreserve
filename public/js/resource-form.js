(() => {
    const type = document.getElementById('resource_type');
    const field = document.getElementById('capacity-field');
    const capacity = document.getElementById('capacity');
    const equipmentFields = document.getElementById('equipment-fields');
    const facilityFields = document.getElementById('facility-fields');
    if (!type || !field || !capacity) return;

    function updateCapacity() {
        const equipment = type.value === 'equipment';
        field.hidden = equipment;
        capacity.disabled = equipment;
        if (equipment) capacity.value = '';
        if (equipmentFields) equipmentFields.hidden = !equipment;
        if (facilityFields) facilityFields.hidden = equipment;
    }

    type.addEventListener('change', updateCapacity);
    updateCapacity();
})();
