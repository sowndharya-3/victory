const FIELDS = [
  ['fullName', 'Full name'],
  ['phone', 'Phone'],
  ['addressLine', 'Address'],
  ['city', 'City'],
  ['district', 'District'],
  ['state', 'State'],
  ['pincode', 'Pincode'],
]

function AddressForm({ value, onChange, errors = {} }) {
  return (
    <fieldset>
      <legend>Delivery address</legend>
      {FIELDS.map(([field, label]) => (
        <div key={field}>
          <label htmlFor={`addr-${field}`}>{label}</label>
          <input
            id={`addr-${field}`}
            className="form-control"
            value={value[field] || ''}
            onChange={e => onChange({ ...value, [field]: e.target.value })}
            aria-invalid={errors[field] ? 'true' : undefined}
            aria-describedby={errors[field] ? `addr-${field}-error` : undefined}
          />
          {errors[field] && <p id={`addr-${field}-error`} className="form-error">{errors[field]}</p>}
        </div>
      ))}
    </fieldset>
  )
}

export default AddressForm
