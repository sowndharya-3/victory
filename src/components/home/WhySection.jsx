const reasons = [
  {
    icon: 'fas fa-award',
    title: 'Experienced Guidance',
    text: 'Personal guidance from our founder and team for your educational needs',
  },
  {
    icon: 'fas fa-handshake',
    title: 'Professional Ethics',
    text: 'End to end solution with professional ethics',
  },
  {
    icon: 'fas fa-rupee-sign',
    title: 'Genuine Fee',
    text: 'Services offered at the right time with genuine fee',
  },
]

function WhySection() {
  return (
    <section className="why-section">
      <div className="container">
        <div className="section-title">
          <h2 className="fade-in">Why Choose Victory Today?</h2>
          <p className="fade-in">We provide comprehensive educational services with a personal touch</p>
        </div>

        <div className="reasons-grid">
          {reasons.map((reason) => (
            <div className="reason-card zoom-in" key={reason.title}>
              <div className="reason-icon">
                <i className={reason.icon}></i>
              </div>
              <h3>{reason.title}</h3>
              <p>{reason.text}</p>
            </div>
          ))}
        </div>

        <div className="reasons-horizontal-scroll">
          {reasons.map((reason) => (
            <div className="reason-card-horizontal zoom-in" key={reason.title}>
              <div className="reason-icon-horizontal">
                <i className={reason.icon}></i>
              </div>
              <h3>{reason.title}</h3>
              <p>{reason.text}</p>
            </div>
          ))}
        </div>
      </div>
    </section>
  )
}

export default WhySection
