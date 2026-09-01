const requestForm = document.querySelector('form');
const recipientModeInputs = document.querySelectorAll('input[name="recipient_mode"]');
const existingRecipientFields = document.getElementById('existing-recipient-fields');
const newRecipientFields = document.getElementById('new-recipient-fields');
const existingRecipientSelect = document.getElementById('recipient_id');
const itemsBody = document.getElementById('request-items-body');
const itemTemplate = document.getElementById('request-item-template');
const addItemButton = document.getElementById('add-item');

if (
    requestForm &&
    existingRecipientFields &&
    newRecipientFields &&
    existingRecipientSelect &&
    itemsBody &&
    itemTemplate &&
    addItemButton
) {
    const newRecipientInputs = newRecipientFields.querySelectorAll('input');

    function updateRecipientMode() {
        const selectedMode = document.querySelector('input[name="recipient_mode"]:checked');
        const isNewRecipient = selectedMode && selectedMode.value === 'new';

        existingRecipientFields.hidden = isNewRecipient;
        newRecipientFields.hidden = !isNewRecipient;
        existingRecipientSelect.disabled = isNewRecipient;
        existingRecipientSelect.required = !isNewRecipient;

        newRecipientInputs.forEach((input) => {
            input.disabled = !isNewRecipient;
            input.required = isNewRecipient;
        });
    }

    recipientModeInputs.forEach((input) => {
        input.addEventListener('change', updateRecipientMode);
    });
    updateRecipientMode();

    addItemButton.addEventListener('click', () => {
        itemsBody.appendChild(itemTemplate.content.cloneNode(true));
    });

    itemsBody.addEventListener('click', (event) => {
        if (!event.target.classList.contains('remove-item')) {
            return;
        }

        const rows = itemsBody.querySelectorAll('tr');
        if (rows.length > 1) {
            event.target.closest('tr').remove();
        }
    });

    requestForm.addEventListener('submit', (event) => {
        const itemSelects = itemsBody.querySelectorAll('select[name="item_id[]"]');
        const quantityInputs = itemsBody.querySelectorAll('input[name="quantity[]"]');
        const selectedItems = new Set();

        for (let index = 0; index < itemSelects.length; index += 1) {
            const itemId = itemSelects[index].value;
            const quantity = Number(quantityInputs[index].value);

            if (!itemId || !Number.isInteger(quantity) || quantity <= 0) {
                event.preventDefault();
                alert('Select an item and enter a positive whole-number quantity.');
                return;
            }

            if (selectedItems.has(itemId)) {
                event.preventDefault();
                alert('The same item can only be added once per request.');
                return;
            }

            selectedItems.add(itemId);
        }
    });
}
