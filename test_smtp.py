import smtplib
from email.message import EmailMessage

try:
    print("Connecting to smtp.gmail.com on port 587...")
    server = smtplib.SMTP('smtp.gmail.com', 587)
    server.set_debuglevel(1)
    server.starttls()
    print("Attempting to login...")
    server.login('zeal.saaes.r311@gmail.com', 'snpcokiyduwrfajt')
    
    msg = EmailMessage()
    msg.set_content("This is a test from Python.")
    msg['Subject'] = "Python SMTP Test"
    msg['From'] = 'zeal.saaes.r311@gmail.com'
    msg['To'] = 'aryan.work0009@gmail.com'
    
    server.send_message(msg)
    server.quit()
    print("Success: Email sent!")
except Exception as e:
    print(f"Error: {e}")
