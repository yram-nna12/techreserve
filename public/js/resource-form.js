(() => {
    const type = document.getElementById('resource_type');
    const field = document.getElementById('capacity-field');
    const capacity = document.getElementById('capacity');
    if (!type || !field || !capacity) return;

    function updateCapacity() {
        const equipment = type.value === 'equipment';
        field.hidden = equipment;
        capacity.disabled = equipment;
        if (equipment) capacity.value = '';
    }

    type.addEventListener('change', updateCapacity);
    updateCapacity();
})();
