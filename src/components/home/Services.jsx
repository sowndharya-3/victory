const services = [
  {
    title: '1. Distance Education Admission Consultancy (5% Service charge)',
    tamil: 'தொலைதூரக் கல்வி சேர்க்கை ஆலோசனை (5% சேவை கட்டணம்)',
    items: [
      'School Education: NIOS (VIII, X, XII, D.T.El.Ed, Basic literacy courses)',
      'Higher Education: IGNOU, Tamilnadu Open University & All other Universities offering Distance/Online Education',
      'All Technical Courses, ITI, Polytechnic, Engineering through Distance Education',
      'All Courses in UG/PG/PhD through Distance Education',
    ],
  },
  {
    title: '2. Regular Education Admission Consultancy (5% Service charge)',
    tamil: 'நேரடி உயர்கல்வி சேர்க்கை ஆலோசனை (5% சேவை கட்டணம்)',
    items: [
      { bold: true, text: 'School Education (Vocational / Technical Education):' },
      'All Technical Courses',
      'ITI, Polytechnic',
      'Engineering',
      { bold: true, text: 'Higher Education:' },
      'All Courses in UG/PG/PhD',
      'All Regular Universities/Colleges',
    ],
  },
  {
    title: '3. Content Writing / Translation',
    tamil: 'உள்ளடக்கம் எழுதுதல் / மொழிபெயர்ப்பு',
    price: 'Tamil Translation: Rs 50/Page',
    description: 'Professional content writing and translation services in Tamil and English.',
    items: [
      'Content Writing: New Course/Lesson Writing',
      'English → Tamil Translation / Tamil → English Translation',
      'Academic Content Development',
      'Technical Writing',
    ],
  },
  {
    title: '4. Books Writing',
    tamil: 'புத்தகங்கள் எழுதுதல்',
    price: 'Premium Service: Rs 4999',
    description: 'Complete book writing services from concept to publication.',
    items: [
      'Own Book / Text Books Writing',
      'Seminar/Workshop/Edited Books Writing',
      'Ghost Writing by Expert Team',
      'Manuscript - Typing, Correction, Publication up to Marketing',
    ],
  },
  {
    title: '5. Research and Development',
    tamil: 'ஆராய்ச்சி மற்றும் மேம்பாடு',
    price: 'Proposal Writing: Rs 1000',
    description: 'Complete research support including proposal writing, literature review, data analysis, and thesis writing.',
    items: [
      'PhD Proposal Writing',
      'Submission of Application',
      'Review of Literature',
      'Data Collection & Analysis',
      'Thesis Writing',
      'Compulsory 2 Research Paper Publications',
      'Printing and Binding',
      'All Other A to Z Services',
    ],
  },
  {
    title: '6. Project/Award Submission',
    tamil: 'திட்டம் / விருது சமர்ப்பிப்பு',
    price: 'Proposal/Report: Rs 1000',
    description: 'Professional assistance for project and award submissions with comprehensive report preparation.',
    items: [
      'Proposal Writing',
      'Submission of Application',
      'Plan of Action',
      'Finance Report',
      'Final Report',
    ],
  },
  {
    title: '7. All Competitive Exam Preparation',
    tamil: 'அனைத்து போட்டித் தேர்வுகள் தயாரிப்பு',
    price: 'Each Module: Rs 1000',
    description: 'Comprehensive preparation for all competitive exams including TNPSC, TRB, SSC, UPSC with study materials and mock tests.',
    items: [
      'TNPSC, TRB, SSC, UPSC Employment: Preparation, Modules, Self-Test, Mock Interview',
      'Study Material - Module Supply and Training/Coaching',
      'Centre/State Government - Training, Coaching by Employment Office',
      'Nan Mudalvan UPSC Coaching',
      'All Other Stipend/Scholarship to Aspirants',
    ],
  },
  {
    title: '8. Entrepreneurship',
    tamil: 'தொழில்முனைவோர் திட்டம்',
    price: 'MSME Registration: Rs 4999',
    description: 'Complete entrepreneurship support including MSME registration, loan applications, and business setup.',
    items: [
      'MSME Registration',
      'Loan Application Submission - Subsidy',
      'Training / Coaching',
      'Central / State Scholarship and Stipend',
      'GST Filing',
      'IT Filing',
    ],
  },
  {
    title: '9. NGO/ Society Registration',
    tamil: 'தன்னார்வல / சங்கப் பதிவு',
    price: 'Deed Writing: Rs 1000',
    description: 'Complete NGO and society registration services including deed writing and documentation.',
    items: [
      'NGO / Society Registration',
      'Core Deed Writing',
      'World / Central / State Funding Process',
      'IT Filing / Year Wise Finance Statement',
      'One to One Training / Coaching',
      'Conduct 1 Day Awareness Programme on NGO Registration',
    ],
  },
  {
    title: '10. SC/ST or MBC or BC Scholarship or Stipend',
    tamil: 'SC/ST அல்லது MBC அல்லது BC உதவித்தொகை / பயிற்சி',
    price: 'Fee Free Counselling',
    description: 'Assistance with scholarship and stipend applications for SC/ST, MBC, and BC communities.',
    items: [
      'Central/State Government Schemes - Awareness Information',
      'Abroad Opportunities / Loan Processing',
      'UGC-NET / CSIR / GATE Coaching',
      'Counselling / Guiding Students Towards Success',
    ],
  },
  {
    title: '11. Sale of Victory Today Publication – Books (10-20% Referral Commission will be given)',
    tamil: 'விக்டரி டுடே பதிப்பகம் – புத்தக விற்பனை (10-20% குறிப்பு கமிஷன் வழங்கப்படும்)',
    description: 'Victory Today Publications - Books for educational and awareness purposes:',
    items: [
      'Sexual Harassment in Educational Institution: Awareness and Prevention – English and Tamil',
      'Child Rights and Child Protection – English and Tamil',
      'Child Rights and Protection – Tamilnadu Teacher Education B.Ed – Textbook – Tamil',
      'Adolescence Education - English and Tamil',
      'How to Start a NGO? (Complete Guide) - English and Tamil',
      'Social Media Usage among Adolescence: Awareness and Prevention - English and Tamil',
      'POSH Act Trainer: Complete Guide (Self Learning) - English and Tamil',
      'NIOS Study Materials',
      'Social Media Awareness Books',
    ],
  },
  {
    title: '12. Career Consultancy, Training and Placement',
    tamil: 'தொழில் ஆலோசனை, பயிற்சி மற்றும் வேலைவாய்ப்பு',
    price: 'TA + DA only; No Remuneration',
    description: 'Comprehensive career guidance, training, and placement assistance for students and professionals.',
    items: [
      'TRB, TNPSC, SSC, UPSC Employment: Consultancy and Training',
      'All IT and MNC Company: Consultancy and Preparation',
      'SSC, UPSC Employment Training',
      'Centre/State Government - Training, Coaching by Victory Today',
    ],
  },
  {
    title: '13. Guidance and Counselling Consultancy (5% Service charge)',
    tamil: 'வழிகாட்டுதல் மற்றும் ஆலோசனை (5% சேவை கட்டணம்)',
    description: 'Comprehensive guidance and counselling services for personal and professional development.',
    items: [
      'Physical and Mental Wellness – Yoga, Meditation, Aerobics and others',
      'Adolescence Education',
      'Parental Education',
      'Senior Citizens – Happy Living Education',
      'Pre-Marital, Marital, Happy Life Coach',
      'Abroad Higher Education Guidance',
      'All Scholarships, Stipends Guidance',
    ],
  },
  {
    title: '14. Stationaries, Computer Devices – (5% Referral Business as per all need)',
    tamil: 'எழுதுபொருட்கள், கணினி சாதனங்கள் – (5% குறிப்பு வணிகம்)',
    description: 'Complete educational supplies and equipment with referral business model.',
    items: [
      'Stationaries',
      'Computer Devices',
      'Smart Board, Projectors',
      'Uniforms',
      'Conduct of Awareness Program, Exhibition, Seminar, Workshop, Conferences',
      'All Needs for Education Institutions',
    ],
  },
]

function Services() {
  return (
    <section className="services-section" id="services">
      <div className="container">
        <div className="section-title">
          <h2 className="fade-in">Our Services</h2>
          <p className="fade-in">Comprehensive educational and professional services for all your needs</p>
        </div>

        <div className="services-grid">
          {services.map((service) => (
            <div className="service-card zoom-in" key={service.title}>
              <div className="service-header">
                <h3>{service.title}</h3>
                <p className="tamil">{service.tamil}</p>
              </div>
              <div className="service-body">
                {service.price && <span className="service-price">{service.price}</span>}
                {service.description && <p>{service.description}</p>}
                <ul>
                  {service.items.map((item, index) =>
                    typeof item === 'string' ? (
                      <li key={index}>{item}</li>
                    ) : (
                      <li key={index}><strong>{item.text}</strong></li>
                    )
                  )}
                </ul>
              </div>
            </div>
          ))}
        </div>
      </div>
    </section>
  )
}

export default Services
