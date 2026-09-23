const reasons = [
  {
    icon: 'fas fa-award',
    title: '32+ Years Experience',
    text: 'The Founder has a 30 years experience in universities and 2 years of company experience',
  },
  {
    icon: 'fas fa-users',
    title: 'Expert Team',
    text: 'All services are by team of various subject retired professors',
  },
  {
    icon: 'fas fa-handshake',
    title: 'Professional Ethics',
    text: 'One to one and End to end solution with professional ethics',
  },
  {
    icon: 'fas fa-clock',
    title: '24x7 Support',
    text: '24x7 easily approachable by Social media - Gmail. Phone from 10 am- 5 pm',
  },
  {
    icon: 'fas fa-rupee-sign',
    title: 'Genuine Fee',
    text: 'World class expertise services offered at right time with genuine fee',
  },
  {
    icon: 'fas fa-user-graduate',
    title: 'Free Counseling',
    text: 'All services before free One to One counselling',
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
