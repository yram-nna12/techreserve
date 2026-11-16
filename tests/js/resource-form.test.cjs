const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const script = fs.readFileSync(path.join(__dirname, '../../public/js/resource-form.js'), 'utf8');

// Exercise the actual form script's state changes without a browser dependency.
function form(typeValue, capacityValue) {
    let change;
    const type = { value: typeValue, addEventListener(event, handler) {
        assert.equal(event, 'change');
        change = handler;
    } };
    const capacity = { value: capacityValue, disabled: false };
    const field = { hidden: false };
    const elements = { resource_type: type, capacity, 'capacity-field': field };
    vm.runInNewContext(script, { document: { getElementById: id => elements[id] } });
    return { type, capacity, field, change: () => change() };
}

test('initial equipment clears zero capacity and disables/hides its field', () => {
    const state = form('equipment', '0');
    assert.equal(state.capacity.value, '');
    assert.equal(state.capacity.disabled, true);
    assert.equal(state.field.hidden, true);
});

test('facility/equipment switching clears stale capacity and re-enables facilities', () => {
    const state = form('facility', '30');
    assert.equal(state.capacity.value, '30');
    assert.equal(state.capacity.disabled, false);
    assert.equal(state.field.hidden, false);
    state.type.value = 'equipment';
    state.change();
    assert.equal(state.capacity.value, '');
    assert.equal(state.capacity.disabled, true);
    assert.equal(state.field.hidden, true);
    state.type.value = 'facility';
    state.change();
    assert.equal(state.capacity.value, '');
    assert.equal(state.capacity.disabled, false);
    assert.equal(state.field.hidden, false);
});
