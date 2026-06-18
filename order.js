console.log('Order JS loaded');

// Inventory loaded from the server (get_inventory.php)
// Structure: { brandKey: [ { id, product_name, stock, sell_price_single, sell_price_double }, ... ] }
let inventory = {};
let selectedProduct = null;      // full product object, not just id
let selectedProductId = null;
let selectedFinish = '';
let selectedType = '';
let selectedTypePrice = 0;
let selectedSilicon = '';
let selectedDelivery = '';
let siliconPadPrice = 30;        // fallback default, overwritten by get_pad_price.php

// ---------------------------------------------------------------------------
// Load inventory from API on page load
// ---------------------------------------------------------------------------
fetch('get_inventory.php')
  .then(function(res) { return res.json(); })
  .then(function(data) {
    data.forEach(function(item) {
      const name = item.product_name.toLowerCase();
      let brand = '';
      if (name.startsWith('iphone'))       brand = 'iphone';
      else if (name.startsWith('galaxy'))  brand = 'samsung';
      else if (name.startsWith('pixel'))   brand = 'pixel';
      else                                 brand = 'other';

      if (!inventory[brand]) inventory[brand] = [];
      inventory[brand].push(item);
    });
  })
  .catch(function(err) {
    console.error('Failed to load inventory:', err);
    alert('Could not load product inventory. Please refresh the page.');
  });

// ---------------------------------------------------------------------------
// Load current silicon pad price from accessories table
// ---------------------------------------------------------------------------
fetch('get_pad_price.php')
  .then(function(res) { return res.json(); })
  .then(function(data) {
    if (data && data.sell_price) {
      siliconPadPrice = parseInt(data.sell_price, 10);
    }
  })
  .catch(function(err) {
    console.error('Failed to load pad price, using fallback K30:', err);
  });

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function updateTotal() {
  let total = selectedTypePrice;
  if (selectedSilicon === true) total += siliconPadPrice;
  if (total > 0) {
    document.getElementById('totalDisplay').textContent = 'K' + total;
    document.getElementById('priceField').style.display = 'flex';
  }
  return total;
}

function resetFromModel() {
  selectedProduct    = null;
  selectedProductId  = null;
  selectedFinish     = '';
  selectedType       = '';
  selectedTypePrice  = 0;
  selectedSilicon    = '';
  selectedDelivery   = '';

  document.querySelectorAll('input[name="finish"]').forEach(function(r)   { r.checked = false; });
  document.querySelectorAll('input[name="caseType"]').forEach(function(r) { r.checked = false; });
  document.querySelectorAll('input[name="silicon"]').forEach(function(r)  { r.checked = false; });
  document.querySelectorAll('input[name="delivery"]').forEach(function(r) { r.checked = false; });
  document.getElementById('location').value = '';

  ['finishField','typeField','siliconField','deliveryField',
   'locationField','priceField','uploadField','paymentField'].forEach(function(id) {
    document.getElementById(id).style.display = 'none';
  });
  document.getElementById('submitBtn').style.display = 'none';
  document.getElementById('totalDisplay').textContent = 'K0';

  // Reset the case type radio labels back to default (no price shown yet)
  updateCaseTypeLabels();
}

// Updates the Single/Double radio button labels to show live prices
function updateCaseTypeLabels() {
  const singleLabel = document.querySelector('label[for="singleBtn"]');
  const doubleLabel = document.querySelector('label[for="doubleBtn"]');
  if (!singleLabel || !doubleLabel) return;

  if (selectedProduct) {
    singleLabel.textContent = 'Single — K' + selectedProduct.sell_price_single;
    doubleLabel.textContent = 'Double — K' + selectedProduct.sell_price_double;
  } else {
    singleLabel.textContent = 'Single';
    doubleLabel.textContent = 'Double';
  }
}

// ---------------------------------------------------------------------------
// Brand select
// ---------------------------------------------------------------------------
document.getElementById('brand').addEventListener('change', function() {
  const brand = this.value;
  const modelSelect = document.getElementById('model');
  modelSelect.innerHTML = '<option value="">-- Select Model --</option>';
  resetFromModel();

  if (brand === '') {
    document.getElementById('modelField').style.display = 'none';
    return;
  }

  const products = inventory[brand] || [];
  if (products.length === 0) {
    document.getElementById('modelField').style.display = 'none';
    alert('No products available for this brand right now.');
    return;
  }

  products.forEach(function(item) {
    const option = document.createElement('option');
    option.value       = item.id;
    option.textContent = item.product_name;
    if (item.stock <= 0) {
      option.textContent += ' (Out of stock)';
      option.disabled = true;
    }
    modelSelect.appendChild(option);
  });

  document.getElementById('modelField').style.display = 'block';
});

// ---------------------------------------------------------------------------
// Model select
// ---------------------------------------------------------------------------
document.getElementById('model').addEventListener('change', function() {
  resetFromModel();
  if (this.value === '') return;

  selectedProductId = parseInt(this.value, 10);

  // Find the full product object so we know its prices
  const brand = document.getElementById('brand').value;
  const products = inventory[brand] || [];
  selectedProduct = products.find(function(p) { return p.id === selectedProductId; }) || null;

  updateCaseTypeLabels();
  document.getElementById('finishField').style.display = 'flex';
});

// ---------------------------------------------------------------------------
// Case finish
// ---------------------------------------------------------------------------
document.querySelectorAll('input[name="finish"]').forEach(function(radio) {
  radio.addEventListener('change', function() {
    selectedFinish = this.value;
    document.getElementById('typeField').style.display = 'block';
  });
});

// ---------------------------------------------------------------------------
// Case type — now pulls live price from selectedProduct instead of fixed numbers
// ---------------------------------------------------------------------------
document.querySelectorAll('input[name="caseType"]').forEach(function(radio) {
  radio.addEventListener('change', function() {
    selectedType = this.value;

    if (selectedProduct) {
      selectedTypePrice = this.value === 'Single'
        ? selectedProduct.sell_price_single
        : selectedProduct.sell_price_double;
    } else {
      // Fallback in case something went wrong loading product data
      selectedTypePrice = this.value === 'Single' ? 120 : 150;
    }

    updateTotal();
    document.getElementById('siliconField').style.display = 'block';
  });
});

// ---------------------------------------------------------------------------
// Silicon pad
// ---------------------------------------------------------------------------
document.querySelectorAll('input[name="silicon"]').forEach(function(radio) {
  radio.addEventListener('change', function() {
    selectedSilicon = this.value === 'Yes';
    updateTotal();
    document.getElementById('deliveryField').style.display = 'block';
  });
});

// ---------------------------------------------------------------------------
// Delivery
// ---------------------------------------------------------------------------
document.querySelectorAll('input[name="delivery"]').forEach(function(radio) {
  radio.addEventListener('change', function() {
    selectedDelivery = this.value;
    document.getElementById('locationField').style.display =
      this.value === 'Delivery' ? 'block' : 'none';
    document.getElementById('uploadField').style.display  = 'block';
    document.getElementById('paymentField').style.display = 'block';
    document.getElementById('submitBtn').style.display    = 'block';
  });
});

// ---------------------------------------------------------------------------
// Design image validation (min 1000×1000, max 5MB)
// ---------------------------------------------------------------------------
const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB

document.getElementById('design').addEventListener('change', function() {
  const file = this.files[0];
  if (!file) return;

  // Check file size first
  if (file.size > MAX_FILE_SIZE) {
    document.getElementById('uploadError').textContent = 'Image is too large (max 5MB). Please compress it and try again.';
    document.getElementById('uploadError').style.display = 'block';
    this.value = '';
    return;
  }

  const img = new Image();
  img.src = URL.createObjectURL(file);
  img.onload = function() {
    const errorEl = document.getElementById('uploadError');
    if (img.width < 1000 || img.height < 1000) {
      errorEl.textContent = 'Image must be at least 1000x1000px';
      errorEl.style.display = 'block';
      document.getElementById('design').value = '';
    } else {
      errorEl.style.display = 'none';
    }
    URL.revokeObjectURL(img.src);
  };
});

// ---------------------------------------------------------------------------
// Payment screenshot size validation (max 5MB)
// ---------------------------------------------------------------------------
document.getElementById('payment').addEventListener('change', function() {
  const file = this.files[0];
  if (!file) return;
  if (file.size > MAX_FILE_SIZE) {
    alert('Payment screenshot is too large (max 5MB). Please compress it and try again.');
    this.value = '';
  }
});

// ---------------------------------------------------------------------------
// Form submit
// ---------------------------------------------------------------------------
document.getElementById('orderForm').addEventListener('submit', function(e) {
  e.preventDefault();

  const name     = document.getElementById('name').value.trim();
  const phone    = document.getElementById('phone').value.trim();
  const brand    = document.getElementById('brand').value;
  const location = document.getElementById('location').value.trim();
  const design   = document.getElementById('design').files[0];
  const payment  = document.getElementById('payment').files[0];
  const total    = updateTotal();

  // --- Validation ---
  if (!name)                { alert('Please enter your name.');                                       document.getElementById('name').focus(); return; }
  if (!phone)               { alert('Please enter your phone number.');                               document.getElementById('phone').focus(); return; }
  if (!brand)               { alert('Please select a phone brand.');                                  return; }
  if (!selectedProductId)   { alert('Please select a phone model.');                                  return; }
  if (!selectedFinish)      { alert('Please select a case finish — Matte or Glossy.');               return; }
  if (!selectedType)        { alert('Please select a case type — Single or Double.');                 return; }
  if (selectedSilicon === '') { alert('Please choose whether you want a silicon suction pad.');       return; }
  if (!selectedDelivery)    { alert('Please select a delivery option.');                              return; }
  if (selectedDelivery === 'Delivery' && !location) {
    alert('Please enter your location for delivery.');
    document.getElementById('location').focus();
    return;
  }
  if (!design)              { alert('Please upload your design image.');                              return; }
  if (!payment)             { alert('Please upload your payment screenshot.');                        return; }

  const submitBtn = document.getElementById('submitBtn');
  submitBtn.textContent = 'Sending...';
  submitBtn.disabled    = true;

  // --- Build FormData ---
  const formData = new FormData();
  formData.append('name',       name);
  formData.append('phone',      phone);
  formData.append('brand',      brand);
  formData.append('product_id', selectedProductId);
  formData.append('finish',     selectedFinish);
  formData.append('caseType',   selectedType);
  formData.append('silicon',    selectedSilicon ? 'Yes' : 'No');
  formData.append('delivery',   selectedDelivery);
  formData.append('location',   location);
  formData.append('total',      total);
  formData.append('design',     design);
  formData.append('payment',    payment);

  fetch('submit_order.php', { method: 'POST', body: formData })
    .then(function(res) { return res.json(); })
    .then(function(data) {
      if (data.success) {
        const nameDisplay  = name;
        const phoneDisplay = phone;
        const orderId      = data.order_id;
        document.getElementById('orderForm').innerHTML = `
          <div style="text-align:center; padding: 40px 0;">
            <div style="font-size: 48px; margin-bottom: 16px;">✅</div>
            <h2 style="font-family:'Syne',sans-serif; color:#2d5a1b; margin-bottom: 8px;">Order Received!</h2>
            <p style="color:#4a7c35;">Thanks, ${nameDisplay}! We'll contact you on ${phoneDisplay} soon.</p>
            <p style="color:#4a7c35; font-size:13px; margin-top:8px;">Order ID: <strong>#${orderId}</strong></p>
            <a href="track.php?order_id=${orderId}&phone=${encodeURIComponent(phoneDisplay)}"
               style="display:inline-block;margin-top:20px;padding:12px 28px;background:#2d5a1b;color:#deecd6;
                      border-radius:100px;font-family:'Syne',sans-serif;font-weight:700;text-decoration:none;">
              Track My Order
            </a>
          </div>
        `;
      } else {
        alert('Something went wrong: ' + data.message);
        submitBtn.textContent = 'Send Order →';
        submitBtn.disabled    = false;
      }
    })
    .catch(function(err) {
      console.error(err);
      alert('Network error details:\n' + err.name + ': ' + err.message);
      submitBtn.textContent = 'Send Order →';
      submitBtn.disabled    = false;
    });
});